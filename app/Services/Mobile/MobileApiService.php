<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Services\Access\AccessControlService;
use App\Services\Auth\AuthService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StripeFee;
use App\Services\Billing\StripeGateway;
use App\Services\Content\ContentService;
use App\Services\MembershipService;
use App\Services\ReservationService;
use App\Support\Clock;

final class MobileApiService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly ReservationService $reservations,
        private readonly MembershipService $memberships,
        private readonly AccessControlService $access,
        private readonly ContentService $content,
        private readonly PaymentService $payments,
    ) {
    }

    public static function make(Database $db): self
    {
        return new self(
            $db,
            AuthService::make($db),
            ReservationService::make($db),
            new MembershipService($db),
            AccessControlService::make($db),
            new ContentService($db),
            new PaymentService($db),
        );
    }

    public function sessionPayload(array $tokens): array
    {
        $expires = Clock::nowUtc()->modify('+' . (int) $tokens['expires_in'] . ' seconds');
        return [
            'accessToken' => $tokens['access_token'],
            'refreshToken' => $tokens['refresh_token'],
            'expiresAt' => Clock::iso($expires->format('Y-m-d H:i:s')),
        ];
    }

    public function memberPayload(array $user): array
    {
        $status = match ((string) $user['status']) {
            'active' => 'active',
            'blocked' => 'blocked',
            default => 'inactive',
        };
        return [
            'id' => $user['public_id'],
            'firstName' => $user['first_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'status' => $status,
            'avatarURL' => $this->avatarURL($user),
        ];
    }

    private function avatarURL(array $user): ?string
    {
        $stored = (string) ($user['avatar_path'] ?? '');
        if ($stored === '' || \App\Services\AvatarService::resolveFile($stored) === null) {
            return null;
        }
        $path = '/uploads/avatars/' . rawurlencode(basename($stored));
        $configured = rtrim((string) config('app.url', ''), '/');
        if (preg_match('#^(https?://[^/]+)#i', $configured, $match) === 1) {
            return $match[1] . $path;
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host === '') {
            return $path;
        }
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded === 'https';
        return ($https ? 'https://' : 'http://') . $host . $path;
    }

    public function issueSession(array $user, Request $request): array
    {
        $platform = strtolower(trim((string) $request->input('platform', 'ios')));
        if (!in_array($platform, ['ios', 'android', 'web', 'other'], true)) {
            $platform = 'ios';
        }
        $deviceName = trim((string) $request->input('deviceName', $request->input('device_name', 'iOS')));
        if ($deviceName === '') {
            $deviceName = 'iOS';
        }
        return $this->sessionPayload($this->auth->issueApiTokens($user, $request, $deviceName, $platform));
    }

    public function live(): array
    {
        $settings = new \App\Services\SettingsService($this->db);
        return [
            'revision' => $settings->liveRevision(),
        ];
    }

    public function gymInfo(): array
    {
        $contact = $this->content->contact();
        $announcements = [];
        foreach ($this->content->faqs() as $faq) {
            $announcements[] = (string) $faq['question'];
            if (count($announcements) >= 3) {
                break;
            }
        }
        return [
            'name' => (string) config('app.name', 'PRIVOFIT'),
            'description' => 'Soukromé fitness studio. Tvůj prostor. Tvůj trénink.',
            'openingHours' => (string) ($contact['hours'] ?? 'Podle rezervací'),
            'announcements' => $announcements,
        ];
    }

    public function offers(): array
    {
        $offers = [];
        foreach ($this->memberships->plans() as $plan) {
            if (in_array((string) ($plan['type'] ?? ''), ['credit', 'lifetime'], true)) {
                continue;
            }
            $price = money_format_czk($plan['price']);
            $offers[] = [
                'id' => $plan['public_id'],
                'name' => $plan['name'],
                'description' => (string) ($plan['description'] ?? ''),
                'priceDescription' => $price,
            ];
        }
        return $offers;
    }

    public function membership(array $user): array
    {
        $row = $this->memberships->activeForUser((int) $user['id']);
        if (!$row) {
            $now = Clock::nowUtc();
            return [
                'title' => 'Bez členství',
                'validUntil' => Clock::iso($now->format('Y-m-d H:i:s')),
                'remainingEntries' => 0,
                'isActive' => false,
                'validFrom' => null,
                'status' => 'inactive',
            ];
        }
        $validUntil = $row['ends_at']
            ? Clock::iso($row['ends_at'])
            : Clock::iso(Clock::nowUtc()->modify('+10 years')->format('Y-m-d H:i:s'));
        $status = 'active';
        if ($row['ends_at']) {
            $endsAt = new \DateTimeImmutable($row['ends_at'], new \DateTimeZone('UTC'));
            if ($endsAt <= Clock::nowUtc()->modify('+14 days')) {
                $status = 'ending';
            }
        }
        return [
            'title' => (string) ($row['plan_name'] ?? 'Členství'),
            'validUntil' => $validUntil,
            'remainingEntries' => $row['entries_remaining'] === null ? null : (int) $row['entries_remaining'],
            'isActive' => true,
            'validFrom' => $row['starts_at'] ? Clock::iso($row['starts_at']) : null,
            'status' => $status,
        ];
    }

    public function membershipPass(array $user): string
    {
        return WalletPassBuilder::fromEnvironment()->build($user, $this->membership($user));
    }

    public function reservations(array $user): array
    {
        $items = [];
        foreach ($this->reservations->forUser((int) $user['id']) as $row) {
            if (!in_array($row['status'], ['confirmed', 'pending_payment'], true)) {
                continue;
            }
            try {
                $items[] = $this->reservationPayload($row, $user);
            } catch (\Throwable) {
                continue;
            }
        }
        return $items;
    }

    public function visits(array $user): array
    {
        $items = [];
        foreach ($this->reservations->forUser((int) $user['id']) as $row) {
            if ($row['status'] !== 'completed') {
                continue;
            }
            $items[] = [
                'id' => $row['public_id'],
                'date' => Clock::iso($row['starts_at']),
                'room' => (string) ($row['room_name'] ?? 'Studio'),
            ];
        }
        return $items;
    }

    public function gyms(): array
    {
        return $this->reservations->gymsForApp();
    }

    public function slots(array $user, string $gymId = ''): array
    {
        return $this->reservations->availableSlotsForApp(14, $gymId, (int) $user['id']);
    }

    public function quote(array $user, array $slotIds, int $guests = 1): array
    {
        $resolved = $this->pricedSlots($this->reservations->resolveSlotIds($slotIds), $guests);
        $sum = 0.0;
        foreach ($resolved as $slot) {
            $sum += (float) ($slot['price'] ?? 0);
        }
        $membership = $this->memberships->activeForUser((int) $user['id']);
        $covered = $this->memberships->coversBooking($membership);
        if ($covered && $membership['entries_remaining'] !== null && (int) $membership['entries_remaining'] < count($resolved)) {
            $covered = false;
        }
        if ($covered) {
            $resolved = array_map(static function (array $slot): array {
                $slot['price'] = '0.00';
                return $slot;
            }, $resolved);
            $sum = 0.0;
        }
        $count = max(1, count($resolved));
        $maxPersons = 1;
        foreach ($resolved as $slot) {
            $maxPersons = max($maxPersons, (int) ($slot['maxPersons'] ?? 1));
        }
        $serviceTotal = number_format($sum, 2, '.', '');
        $priced = $sum > 0 ? StripeFee::cover($serviceTotal) : [
            'net' => '0.00',
            'fee' => '0.00',
            'charge' => '0.00',
            'chargeMinor' => 0,
        ];
        return [
            'slots' => $resolved,
            'pricePerSlot' => number_format($sum / $count, 2, '.', ''),
            'total' => $priced['charge'],
            'serviceTotal' => $priced['net'],
            'processingFee' => $priced['fee'],
            'currencyCode' => 'CZK',
            'guests' => $this->clampGuests($guests, $maxPersons),
            'maxPersons' => $maxPersons,
        ];
    }

    public function reserve(array $user, string $slotId, string $requestId, int $guests = 1): array
    {
        if ($cached = $this->idempotent($user, $requestId)) {
            return $cached;
        }
        $reservation = $this->reservations->createFromSlotId($user, $slotId, false, [], $guests);
        $payload = $this->reservationPayload($reservation, $user);
        $this->storeIdempotent($user, $requestId, 'reservations', $payload);
        return $payload;
    }

    public function cancel(array $user, string $id, string $requestId): array
    {
        if ($cached = $this->idempotent($user, $requestId)) {
            return $cached;
        }
        $this->reservations->cancel($user, $id);
        $payload = ['ok' => true];
        $this->storeIdempotent($user, $requestId, 'reservations.cancel', $payload);
        return $payload;
    }

    public function payAndReserve(array $user, array $slotIds, string $requestId, array $applePay, int $guests = 1): array
    {
        $this->ensureCheckoutSchema();
        if ($cached = $this->idempotent($user, $requestId)) {
            return $cached;
        }
        $existing = $this->existingPaidCheckout($user, $requestId);
        if ($existing) {
            $payload = $this->paymentPayloadFromRow($existing, $user);
            $this->storeIdempotent($user, $requestId, 'reservations.pay', $payload);
            return $payload;
        }

        $quote = $this->quote($user, $slotIds, $guests);
        $count = count($quote['slots']);
        $unit = (float) $quote['pricePerSlot'];
        $net = (string) ($quote['serviceTotal'] ?? '0.00');
        $charge = (string) ($quote['total'] ?? $net);
        $fee = (string) ($quote['processingFee'] ?? '0.00');
        $bookedGuests = (int) ($quote['guests'] ?? 1);
        $holds = [];
        try {
            $this->reservations->releasePendingForSlots($user, $slotIds);
            $holds = $this->reservations->createFromSlotIds($user, $slotIds, true, [], $bookedGuests);

            $settlement = $this->settleCheckout($user, $unit, $count, $net, $requestId, $applePay, $holds);
            if ($settlement['provider'] === 'membership') {
                $membership = $this->memberships->activeForUser((int) $user['id']);
                $membershipId = $membership ? (int) $membership['id'] : null;
                foreach ($holds as $i => $hold) {
                    $this->db->update('reservations', [
                        'price' => '0.00',
                        'membership_id' => $membershipId,
                        'updated_at' => Clock::utc(),
                    ], 'id = :id', ['id' => (int) $hold['id']]);
                    $holds[$i]['price'] = '0.00';
                    $holds[$i]['membership_id'] = $membershipId;
                }
            }
            $confirmed = [];
            foreach ($holds as $hold) {
                $confirmed[] = $this->reservations->confirmPending($hold, $user);
            }
            $firstId = isset($confirmed[0]['id']) ? (int) $confirmed[0]['id'] : null;
            $paymentId = $this->insertPayment([
                'public_id' => Crypto::uuid(),
                'client_request_id' => $requestId !== '' ? $requestId : null,
                'user_id' => (int) $user['id'],
                'reservation_id' => $firstId,
                'provider' => $settlement['provider'],
                'provider_reference' => $settlement['reference'],
                'amount' => $settlement['provider'] === 'stripe' ? $net : $charge,
                'fee_amount' => $settlement['provider'] === 'stripe' ? (string) ($settlement['fee'] ?? $fee) : '0.00',
                'charged_amount' => (string) ($settlement['charged'] ?? $charge),
                'currency' => 'CZK',
                'status' => 'paid',
                'metadata_json' => json_encode([
                    'reservations' => array_column($confirmed, 'public_id'),
                    'settlement' => $settlement['provider'],
                    'net' => $net,
                    'fee' => $fee,
                    'charged' => $charge,
                ], JSON_UNESCAPED_UNICODE),
                'paid_at' => Clock::utc(),
                'created_at' => Clock::utc(),
                'updated_at' => Clock::utc(),
            ]);
            if ($settlement['provider'] === 'stripe' && is_string($settlement['reference']) && $settlement['reference'] !== '') {
                $this->payments->captureStripeFacts($paymentId, $settlement['reference']);
            }
            $payload = [
                'id' => $requestId !== '' ? $requestId : ($confirmed[0]['public_id'] ?? Crypto::uuid()),
                'status' => 'paid',
                'checkoutURL' => null,
                'reservations' => array_map(fn (array $row): array => $this->reservationPayload($row, $user), $confirmed),
            ];
            $this->storeIdempotent($user, $requestId, 'reservations.pay', $payload);
            return $payload;
        } catch (\Throwable $e) {
            foreach ($holds as $hold) {
                $this->reservations->failPending($hold);
            }
            if ($e instanceof HttpException) {
                throw $e;
            }
            Logger::error('Checkout rezervace selhal', ['error' => $e->getMessage()]);
            throw new HttpException(500, 'Rezervaci se nepodařilo dokončit.');
        }
    }

    public function inbox(array $user): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 40',
                ['uid' => (int) $user['id']]
            );
        } catch (\Throwable) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true) ?: [];
            $items[] = [
                'id' => (string) $row['id'],
                'title' => (string) ($payload['subject'] ?? $row['template']),
                'body' => (string) ($payload['body'] ?? $payload['subject'] ?? 'Aktualizace k tvému účtu PRIVOFIT.'),
                'date' => Clock::iso($row['created_at']),
            ];
        }
        return $items;
    }

    public function eligibility(array $user): array
    {
        $auth = $this->access->canAttempt($user);
        $door = $this->db->fetch('SELECT * FROM doors WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        $reservation = $auth['reservation'] ?? null;
        $until = Clock::nowUtc()->modify('+2 minutes');
        if ($reservation) {
            $buffer = max(0, (int) ($reservation['buffer_minutes'] ?? 0));
            $until = (new \DateTimeImmutable($reservation['ends_at'], new \DateTimeZone('UTC')))->modify('+' . $buffer . ' minutes');
        }
        $reason = null;
        if (!$auth['allowed']) {
            $reason = ($auth['reasons'][0] ?? null) === 'unverified'
                ? 'Nejprve ověř e-mail.'
                : (($auth['reservation'] ?? null) ? 'Účet nemá oprávnění ke vstupu.' : 'Nemáš právě platnou rezervaci.');
        }
        return [
            'allowed' => (bool) $auth['allowed'],
            'reason' => $reason,
            'doorID' => $door['public_id'] ?? 'door',
            'expiresAt' => Clock::iso($until->format('Y-m-d H:i:s')),
            'doorName' => $door['name'] ?? 'Vstupní dveře',
        ];
    }

    public function openDoor(array $user, string $doorId, string $requestId, string $ip): array
    {
        $existing = $this->db->fetch(
            'SELECT * FROM door_commands WHERE user_id = :uid AND request_id = :rid',
            ['uid' => (int) $user['id'], 'rid' => $requestId]
        );
        if ($existing) {
            return $this->doorReceipt($existing);
        }
        try {
            $result = $this->db->transaction(function () use ($user, $ip) {
                return $this->access->open($user, $ip);
            });
            $outcome = !empty($result['physical_open_confirmed']) ? 'confirmedOpen' : 'accepted';
            $message = (string) ($result['message'] ?? '');
        } catch (HttpException $e) {
            $outcome = 'denied';
            $message = $e->getMessage();
            $result = [];
        }
        $door = $this->db->fetch('SELECT * FROM doors WHERE public_id = :pid OR public_id = :pid2 LIMIT 1', [
            'pid' => $doorId,
            'pid2' => $doorId,
        ]) ?: $this->db->fetch('SELECT * FROM doors WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        $entry = null;
        if ($outcome !== 'denied') {
            $current = $this->reservations->current((int) $user['id']);
            if ($current) {
                $entry = Clock::iso($current['ends_at']);
            }
        }
        $operationId = Crypto::uuid();
        $this->db->insert('door_commands', [
            'public_id' => $operationId,
            'user_id' => (int) $user['id'],
            'request_id' => $requestId,
            'door_id' => $door['id'] ?? null,
            'outcome' => $outcome,
            'message' => $message,
            'entry_until' => $entry ? (new \DateTimeImmutable($current['ends_at'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null,
            'created_at' => Clock::utc(),
        ]);
        return [
            'operationID' => $operationId,
            'outcome' => $outcome,
            'message' => $message !== '' ? $message : null,
            'entryUntil' => $entry,
        ];
    }

    public function doorStatus(array $user, string $requestId, ?string $operationId): array
    {
        $row = $this->db->fetch(
            'SELECT * FROM door_commands WHERE user_id = :uid AND request_id = :rid',
            ['uid' => (int) $user['id'], 'rid' => $requestId]
        );
        if (!$row && $operationId) {
            $row = $this->db->fetch(
                'SELECT * FROM door_commands WHERE user_id = :uid AND public_id = :pid',
                ['uid' => (int) $user['id'], 'pid' => $operationId]
            );
        }
        if (!$row) {
            throw new HttpException(404, 'Příkaz ke dveřím nebyl nalezen.');
        }
        return $this->doorReceipt($row);
    }

    public function registerPush(array $user, Request $request, string $token, array $preferences): void
    {
        $payload = Crypto::verifyPayload((string) $request->bearerToken());
        $deviceId = isset($payload['did']) ? (int) $payload['did'] : 0;
        $token = substr(trim($token), 0, 512);
        if ($deviceId < 1 || $token === '') {
            return;
        }
        $userId = (int) $user['id'];
        $now = Clock::utc();
        $hash = hash('sha256', $token);
        $environment = strtolower(trim((string) ($request->input('environment') ?? 'production')));
        if (!in_array($environment, ['development', 'production'], true)) {
            $environment = 'production';
        }

        $this->db->query(
            'UPDATE fcm_tokens
             SET is_active = 0, invalidated_at = :now, invalid_reason = :reason
             WHERE device_id = :did AND is_active = 1 AND token_hash <> :hash',
            [
                'now' => $now,
                'reason' => 'replaced',
                'did' => $deviceId,
                'hash' => $hash,
            ]
        );
        $this->db->query(
            'INSERT INTO fcm_tokens (user_id, device_id, token, token_hash, environment, is_active, last_used_at)
             VALUES (:uid, :did, :token, :hash, :env, 1, :seen)
             ON DUPLICATE KEY UPDATE
                token = VALUES(token),
                user_id = VALUES(user_id),
                device_id = VALUES(device_id),
                environment = VALUES(environment),
                is_active = 1,
                invalidated_at = NULL,
                invalid_reason = NULL,
                last_used_at = VALUES(last_used_at)',
            [
                'uid' => $userId,
                'did' => $deviceId,
                'token' => $token,
                'hash' => $hash,
                'env' => $environment,
                'seen' => $now,
            ]
        );
        $this->db->update('api_devices', [
            'push_token' => $token,
            'push_preferences_json' => json_encode($preferences, JSON_UNESCAPED_UNICODE),
            'last_seen_at' => $now,
        ], 'id = :id AND user_id = :uid', [
            'id' => $deviceId,
            'uid' => $userId,
        ]);
    }

    public function revokeCurrentDevice(Request $request): void
    {
        $payload = Crypto::verifyPayload((string) $request->bearerToken());
        if (!$payload || empty($payload['did'])) {
            return;
        }
        $deviceId = (int) $payload['did'];
        $now = Clock::utc();
        $this->db->update(
            'api_refresh_tokens',
            ['revoked_at' => $now],
            'device_id = :did AND revoked_at IS NULL',
            ['did' => $deviceId]
        );
        $this->db->query(
            'UPDATE fcm_tokens
             SET is_active = 0, invalidated_at = :now, invalid_reason = :reason
             WHERE device_id = :did AND is_active = 1',
            [
                'now' => $now,
                'reason' => 'device_revoked',
                'did' => $deviceId,
            ]
        );
        $this->db->update('api_devices', [
            'push_token' => null,
        ], 'id = :id', ['id' => $deviceId]);
    }

    private function reservationPayload(array $row, array $user): array
    {
        if (empty($row['room_name']) || empty($row['room_public_id'])) {
            $room = $this->db->fetch('SELECT name, public_id FROM rooms WHERE id = :id', ['id' => (int) $row['room_id']]);
            $row['room_name'] = $row['room_name'] ?? ($room['name'] ?? 'Studio');
            $row['room_public_id'] = $row['room_public_id'] ?? ($room['public_id'] ?? '');
        }
        $starts = new \DateTimeImmutable($row['starts_at'], new \DateTimeZone('UTC'));
        $canCancel = in_array($row['status'], ['confirmed', 'pending_payment'], true)
            && $starts > Clock::nowUtc();
        return [
            'id' => $row['public_id'],
            'start' => Clock::iso($row['starts_at']),
            'end' => Clock::iso($row['ends_at']),
            'room' => (string) $row['room_name'],
            'canCancel' => $canCancel,
            'bufferMinutes' => (int) ($row['buffer_minutes'] ?? 15),
            'guestCount' => max(1, (int) ($row['guest_count'] ?? 1)),
            'price' => number_format((float) ($row['price'] ?? 0), 2, '.', ''),
            'currencyCode' => (string) ($row['currency'] ?? 'CZK'),
            'gymID' => (string) ($row['room_public_id'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $slots @return list<array<string, mixed>> */
    private function pricedSlots(array $slots, int $guests): array
    {
        $maxPersons = 1;
        foreach ($slots as $slot) {
            $maxPersons = max($maxPersons, (int) ($slot['maxPersons'] ?? 1));
        }
        $guests = $this->clampGuests($guests, $maxPersons);
        return array_map(static function (array $slot) use ($guests): array {
            $one = (float) ($slot['price'] ?? 0);
            $two = (float) ($slot['priceTwo'] ?? $one);
            $slot['price'] = number_format($guests >= 2 ? $two : $one, 2, '.', '');
            return $slot;
        }, $slots);
    }

    private function clampGuests(int $guests, int $maxPersons): int
    {
        $maxPersons = max(1, $maxPersons);
        if ($guests < 1 || $guests > $maxPersons) {
            throw new HttpException(422, 'Neplatný počet osob.');
        }
        return $guests;
    }

    private function doorReceipt(array $row): array
    {
        return [
            'operationID' => $row['public_id'],
            'outcome' => $row['outcome'],
            'message' => $row['message'] !== null && $row['message'] !== '' ? $row['message'] : null,
            'entryUntil' => $row['entry_until'] ? Clock::iso($row['entry_until']) : null,
        ];
    }

    private function applePayJson(array $applePay): ?string
    {
        $raw = $applePay['paymentData'] ?? '';
        if (is_array($raw)) {
            $json = json_encode($raw);
            $parsed = $raw;
        } else {
            $raw = is_string($raw) ? $raw : '';
            $decoded = base64_decode($raw, true);
            $json = $decoded !== false ? $decoded : $raw;
            $parsed = json_decode($json, true);
        }
        $looksLikeApplePay = is_array($parsed)
            && isset($parsed['data'], $parsed['signature'], $parsed['header']);
        if (!$looksLikeApplePay) {
            return null;
        }
        return is_string($json) ? $json : json_encode($parsed);
    }

    /**
     * @param list<array<string, mixed>> $holds
     * @return array{provider:string,reference:?string,fee:string,charged:string}
     */
    private function settleCheckout(array $user, float $unit, int $count, string $net, string $requestId, array $applePay, array $holds = []): array
    {
        if ($unit <= 0) {
            $membership = $this->memberships->activeForUser((int) $user['id']);
            if ($membership && $membership['entries_remaining'] !== null) {
                for ($i = 0; $i < $count; $i++) {
                    $this->memberships->consumeEntry((int) $membership['id'], (int) $user['id']);
                }
            }
            return ['provider' => 'membership', 'reference' => null, 'fee' => '0.00', 'charged' => '0.00'];
        }

        $netMinor = (int) round(((float) $net) * 100);
        $key = trim((string) env_value('STRIPE_SECRET_KEY', ''));
        if ($key === '' || !str_starts_with($key, 'sk_')) {
            throw new HttpException(503, 'Stripe není nakonfigurovaný.');
        }

        $slotLocal = isset($holds[0]['starts_at']) ? Clock::format((string) $holds[0]['starts_at'], 'j. n. Y H:i') : Clock::nowLocal()->format('j. n. Y H:i');
        $paymentData = $this->applePayJson($applePay);
        $gateway = StripeGateway::fromConfig();
        $description = 'PRIVOFIT rezervace ' . $slotLocal . ' (Europe/Prague)';
        $meta = [
            'user' => (string) ($user['public_id'] ?? $user['id'] ?? ''),
            'request' => $requestId,
            'slots' => (string) $count,
            'local_time' => $slotLocal,
            'timezone' => 'Europe/Prague',
        ];

        if ($paymentData !== null) {
            $charge = $gateway->chargeApplePay($paymentData, $netMinor, 'czk', $requestId, $description, $meta);
            return ['provider' => 'stripe', 'reference' => $charge['id'], 'fee' => $charge['fee'], 'charged' => $charge['charge']];
        }
        if (str_starts_with($key, 'sk_test_')) {
            $charge = $gateway->chargeTestCard($netMinor, 'czk', $requestId, $description . ' (test)', $meta);
            return ['provider' => 'stripe', 'reference' => $charge['id'], 'fee' => $charge['fee'], 'charged' => $charge['charge']];
        }

        throw new HttpException(422, 'Chybí Apple Pay token.');
    }

    private function existingPaidCheckout(array $user, string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }
        try {
            $existing = $this->db->fetch(
                'SELECT * FROM payments WHERE user_id = :uid AND client_request_id = :rid',
                ['uid' => (int) $user['id'], 'rid' => $requestId]
            );
        } catch (\Throwable) {
            return null;
        }
        if ($existing && $existing['status'] === 'paid') {
            return $existing;
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    private function insertPayment(array $data): int
    {
        try {
            return (int) $this->db->insert('payments', $data);
        } catch (\Throwable) {
            unset($data['client_request_id'], $data['metadata_json'], $data['fee_amount'], $data['charged_amount']);
            return (int) $this->db->insert('payments', $data);
        }
    }

    private function ensureCheckoutSchema(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        $this->trySql(
            "CREATE TABLE IF NOT EXISTS api_idempotency (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                idempotency_key CHAR(36) NOT NULL,
                route VARCHAR(120) NOT NULL,
                status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                response_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_api_idempotency_user_key (user_id, idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->trySql('ALTER TABLE payments ADD COLUMN client_request_id CHAR(36) DEFAULT NULL AFTER public_id');
        $this->trySql('ALTER TABLE payments ADD COLUMN metadata_json MEDIUMTEXT DEFAULT NULL AFTER status');
        $this->trySql('ALTER TABLE payments ADD COLUMN fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount');
        $this->trySql('ALTER TABLE payments ADD COLUMN charged_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER fee_amount');
        $this->trySql('ALTER TABLE payments ADD COLUMN stripe_details MEDIUMTEXT DEFAULT NULL AFTER metadata_json');
    }

    private function trySql(string $sql): void
    {
        try {
            $this->db->query($sql);
        } catch (\Throwable) {
            // sloupec / tabulka už existuje
        }
    }

    private function allowLocalStripeTest(): bool
    {
        $env = (string) env_value('APP_ENV', 'production');
        $key = trim((string) env_value('STRIPE_SECRET_KEY', ''));
        return $env === 'local' && str_starts_with($key, 'sk_test_');
    }

    private function paymentPayloadFromRow(array $payment, array $user): array
    {
        $meta = json_decode((string) ($payment['metadata_json'] ?? ''), true) ?: [];
        $ids = $meta['reservations'] ?? [];
        $reservations = [];
        foreach ($ids as $id) {
            $row = $this->db->fetch(
                'SELECT r.*, rm.name AS room_name FROM reservations r INNER JOIN rooms rm ON rm.id = r.room_id WHERE r.public_id = :pid',
                ['pid' => $id]
            );
            if ($row) {
                $reservations[] = $this->reservationPayload($row, $user);
            }
        }
        return [
            'id' => $payment['client_request_id'] ?: $payment['public_id'],
            'status' => $payment['status'] === 'paid' ? 'paid' : ($payment['status'] === 'failed' ? 'failed' : 'pending'),
            'checkoutURL' => null,
            'reservations' => $reservations,
        ];
    }

    private function idempotent(array $user, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        try {
            $row = $this->db->fetch(
                'SELECT response_json FROM api_idempotency WHERE user_id = :uid AND idempotency_key = :k',
                ['uid' => (int) $user['id'], 'k' => $key]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) $row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function storeIdempotent(array $user, string $key, string $route, array $payload): void
    {
        if ($key === '') {
            return;
        }
        try {
            $this->db->insert('api_idempotency', [
                'user_id' => (int) $user['id'],
                'idempotency_key' => $key,
                'route' => $route,
                'status_code' => 200,
                'response_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => Clock::utc(),
            ]);
        } catch (\Throwable) {
            // duplicitní klíč – první odpověď zůstává
        }
    }
}
