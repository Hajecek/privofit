<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StripeGateway;
use App\Support\Clock;

final class ReservationService
{
    public const REFUND_SECONDS = 120;

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly MembershipService $memberships,
        private readonly MailService $mail,
    ) {
    }

    public static function make(Database $db): self
    {
        $settings = new SettingsService($db);
        return new self($db, $settings, new MembershipService($db), new MailService($db));
    }

    public function availability(string $localDate, ?int $roomId = null, ?int $userId = null): array
    {
        $room = $this->room($roomId);
        $slotMinutes = $this->slotMinutes();
        $minMinutes = $this->settings->int('reservation.min_minutes', 60);
        $maxMinutes = $this->settings->int('reservation.max_minutes', 1440);
        $durationStep = $this->durationStep();
        $buffer = $this->bufferMinutes();
        $maxPersons = (int) $room['max_persons'];

        $day = Clock::parseLocal($localDate . ' 00:00:00');
        $hours = $this->hoursForDate($room, $day);
        $hourlyPrice = $this->hourlyFromHours($hours);
        $meta = [
            'date' => $localDate,
            'slot_minutes' => $slotMinutes,
            'min_minutes' => $minMinutes,
            'max_minutes' => $maxMinutes,
            'duration_step_minutes' => $durationStep,
            'buffer_minutes' => $buffer,
            'block_minutes' => $durationStep + $buffer,
            'max_persons' => $maxPersons,
            'hourly_price' => number_format($hourlyPrice, 2, '.', ''),
            'hourly_price_two' => number_format($this->rateForGuests(2, $hourlyPrice), 2, '.', ''),
        ];
        if ($hours['closed']) {
            return $meta + ['closed' => true, 'slots' => []];
        }

        $open = Clock::parseLocal($localDate . ' ' . $hours['opens_at']);
        $close = Clock::parseLocal($localDate . ' ' . $hours['closes_at']);
        $maxMinutes = $this->allowedTrainingMinutes($open, $close);
        $meta['max_minutes'] = $maxMinutes;
        $nowLocal = Clock::nowLocal();
        $occupied = $this->occupiedIntervals(
            (int) $room['id'],
            Clock::toUtc($open)->modify('-6 hours')->format('Y-m-d H:i:s'),
            Clock::toUtc($close)->modify('+6 hours')->format('Y-m-d H:i:s')
        );

        $durations = [];
        for ($m = $minMinutes; $m <= $maxMinutes; $m += $durationStep) {
            $durations[] = $m;
        }

        $blockMinutes = $durationStep + $buffer;
        $slots = [];
        foreach ($this->startCandidates($open, $close, $occupied, $blockMinutes) as $cursor) {
            $fits = [];
            $availableFor = [];
            foreach ($durations as $minutes) {
                $span = $this->spanMinutes($minutes);
                $until = $cursor->modify('+' . $span . ' minutes');
                if ($until > $close) {
                    continue;
                }
                $fits[] = $minutes;
                $trainingEnd = $until->modify('-' . $buffer . ' minutes');
                $utcStart = Clock::toUtc($cursor);
                if ($utcStart > Clock::nowUtc() && !$this->overlaps($occupied, $utcStart, Clock::toUtc($trainingEnd), $buffer)) {
                    $availableFor[] = $minutes;
                }
            }
            $blockEnd = $cursor->modify('+' . $blockMinutes . ' minutes');
            if ($blockEnd > $close) {
                continue;
            }
            $utcStart = Clock::toUtc($cursor);
            $blockEndUtc = Clock::toUtc($blockEnd);
            $past = $utcStart <= Clock::nowUtc();
            $free = in_array($minMinutes, $availableFor, true);
            $taken = $this->overlapsBody($occupied, $utcStart, $blockEndUtc);
            $mine = $userId !== null && $this->overlapsOwned($occupied, $utcStart, $blockEndUtc, $userId);
            if (!$free && !$past && !$taken && !$mine) {
                continue;
            }
            $kind = 'free';
            if ($mine) {
                $kind = 'mine';
            } elseif ($past) {
                $kind = 'past';
            } elseif (!$free) {
                $kind = 'busy';
            }
            $slots[] = [
                'start' => $cursor->format('H:i'),
                'end' => $blockEnd->format('H:i'),
                'start_at' => $utcStart->format('Y-m-d H:i:s'),
                'past' => $past,
                'available' => $free && !$mine,
                'mine' => $mine,
                'kind' => $kind,
                'fits' => $fits,
                'available_for' => $availableFor,
            ];
        }

        return $meta + [
            'closed' => false,
            'slots' => $slots,
            'now' => $nowLocal->format('Y-m-d H:i:s'),
        ];
    }

    /** @return list<array{date:string,closed:bool,free:int,mine:bool}> */
    public function monthOverview(int $year, int $month, ?int $roomId = null, ?int $userId = null): array
    {
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
            throw new HttpException(422, 'Neplatný měsíc.');
        }
        $start = \DateTimeImmutable::createFromFormat('Y-n-j', $year . '-' . $month . '-1', new \DateTimeZone(Clock::displayTimezone()));
        if (!$start) {
            throw new HttpException(422, 'Neplatný měsíc.');
        }
        $start = $start->setTime(0, 0);
        $last = (int) $start->format('t');
        $end = $start->setDate($year, $month, $last)->setTime(23, 59, 59);
        $room = $this->room($roomId);
        $buffer = $this->bufferMinutes();
        $hourMinutes = $this->durationStep();
        $blockMinutes = $hourMinutes + $buffer;
        $weekHours = [];
        foreach ($this->db->fetchAll('SELECT * FROM opening_hours WHERE room_id = :rid', ['rid' => (int) $room['id']]) as $row) {
            $weekHours[(int) $row['weekday']] = $row;
        }
        $exceptions = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM opening_hour_exceptions WHERE room_id = :rid AND exception_date BETWEEN :a AND :b',
            ['rid' => (int) $room['id'], 'a' => $start->format('Y-m-d'), 'b' => $end->format('Y-m-d')]
        ) as $row) {
            $exceptions[(string) $row['exception_date']] = $row;
        }
        $rangeFrom = Clock::toUtc($start->modify('-6 hours'))->format('Y-m-d H:i:s');
        $rangeTo = Clock::toUtc($end->modify('+6 hours'))->format('Y-m-d H:i:s');
        $occupied = $this->occupiedIntervals((int) $room['id'], $rangeFrom, $rangeTo);
        $mineDates = $this->ownedDates((int) $room['id'], $rangeFrom, $rangeTo, $userId);
        $nowUtc = Clock::nowUtc();
        $days = [];
        for ($day = 1; $day <= $last; $day++) {
            $localDay = $start->setDate($year, $month, $day);
            $date = $localDay->format('Y-m-d');
            $mine = isset($mineDates[$date]);
            $hours = $this->hoursFromMaps($weekHours, $exceptions[$date] ?? null, (int) $localDay->format('N'));
            if ($hours['closed']) {
                $days[] = ['date' => $date, 'closed' => true, 'free' => 0, 'mine' => $mine];
                continue;
            }
            $open = Clock::parseLocal($date . ' ' . $hours['opens_at']);
            $close = Clock::parseLocal($date . ' ' . $hours['closes_at']);
            $free = 0;
            foreach ($this->startCandidates($open, $close, $occupied, $blockMinutes) as $cursor) {
                $slotEnd = $cursor->modify('+' . $hourMinutes . ' minutes');
                $blockEnd = $cursor->modify('+' . $blockMinutes . ' minutes');
                if ($blockEnd > $close) {
                    continue;
                }
                $utcStart = Clock::toUtc($cursor);
                if ($utcStart > $nowUtc && !$this->overlaps($occupied, $utcStart, Clock::toUtc($slotEnd), $buffer)) {
                    $free++;
                }
            }
            $days[] = ['date' => $date, 'closed' => false, 'free' => $free, 'mine' => $mine];
        }
        return $days;
    }

    /** @param array<int, array<string, mixed>> $weekHours */
    private function hoursFromMaps(array $weekHours, ?array $exception, int $weekday): array
    {
        $hours = $weekHours[$weekday] ?? null;
        $hourlyPrice = $hours['hourly_price'] ?? null;
        if ($exception) {
            return [
                'closed' => (int) $exception['is_closed'] === 1,
                'opens_at' => $exception['opens_at'] ?? '06:00:00',
                'closes_at' => $exception['closes_at'] ?? '22:00:00',
                'hourly_price' => $hourlyPrice,
            ];
        }
        if (!$hours || (int) $hours['is_closed'] === 1) {
            return ['closed' => true, 'opens_at' => '00:00:00', 'closes_at' => '00:00:00', 'hourly_price' => $hourlyPrice];
        }
        return [
            'closed' => false,
            'opens_at' => $hours['opens_at'],
            'closes_at' => $hours['closes_at'],
            'hourly_price' => $hourlyPrice,
        ];
    }

    public function create(array $user, string $localStart, int $durationMinutes, int $guestCount, ?int $roomId = null, bool $paidCheckout = false, array $ignoreReservationIds = []): array
    {
        if ($user['status'] !== 'active' && $user['status'] !== 'pending') {
            throw new HttpException(403, 'Účet není aktivní.');
        }
        if (!$paidCheckout && empty($user['email_verified_at'])) {
            throw new HttpException(403, 'Nejprve ověřte e-mailovou adresu.');
        }

        $room = $this->room($roomId);
        $min = $this->settings->int('reservation.min_minutes', 60);
        $buffer = $this->bufferMinutes();
        $durationStep = $this->durationStep();
        if ($durationMinutes < $min || $durationMinutes % $durationStep !== 0) {
            throw new HttpException(422, 'Neplatná délka rezervace.');
        }
        if ($guestCount < 1 || $guestCount > (int) $room['max_persons']) {
            throw new HttpException(422, 'Neplatný počet osob.');
        }

        $startLocal = Clock::parseLocal($localStart);
        $endLocal = $startLocal->modify('+' . ($this->spanMinutes($durationMinutes) - $buffer) . ' minutes');
        $startUtc = Clock::toUtc($startLocal);
        $endUtc = Clock::toUtc($endLocal);

        if ($startUtc <= Clock::nowUtc()) {
            throw new HttpException(422, 'Nelze rezervovat termín v minulosti.');
        }

        $hours = $this->hoursForDate($room, $startLocal);
        if ($hours['closed']) {
            throw new HttpException(422, 'Fitko je v tento den zavřené.');
        }
        $open = Clock::parseLocal($startLocal->format('Y-m-d') . ' ' . $hours['opens_at']);
        $close = Clock::parseLocal($startLocal->format('Y-m-d') . ' ' . $hours['closes_at']);
        if ($durationMinutes > $this->allowedTrainingMinutes($open, $close)) {
            throw new HttpException(422, 'Neplatná délka rezervace.');
        }
        $occupiedUntil = $endLocal->modify('+' . $buffer . ' minutes');
        if ($startLocal < $open || $occupiedUntil > $close) {
            throw new HttpException(422, 'Termín je mimo provozní dobu.');
        }

        $price = $this->priceForDuration($durationMinutes, $this->hourlyFromHours($hours), $guestCount);
        $blocks = max(1, intdiv($durationMinutes, $durationStep));
        $membership = $this->memberships->activeForUser((int) $user['id']);
        $canCover = $this->memberships->coversBooking($membership, $blocks);
        if (!$paidCheckout && !$canCover && $this->memberships->coversBooking($membership, 1)) {
            throw new HttpException(422, 'Na ' . $this->blocksPhrase($blocks) . ' nemáš dost vstupů. Můžeš termín zaplatit.');
        }
        $useMembership = !$paidCheckout && $canCover;

        $this->expireHolds();
        $this->ensureOccupancyTable();
        $lockName = 'pf-room-' . (int) $room['id'];
        $got = $this->db->fetchColumn("SELECT GET_LOCK('{$lockName}', 8)");
        if ((int) $got !== 1) {
            throw new HttpException(409, 'Tento termín se právě rezervuje. Zkus to za chvíli znovu.');
        }

        try {
            $reservation = $this->db->transaction(function (Database $db) use ($room, $user, $startUtc, $endUtc, $startLocal, $open, $buffer, $durationStep, $guestCount, $price, $membership, $useMembership, $ignoreReservationIds, $blocks) {
                $db->query('SELECT id FROM rooms WHERE id = :id FOR UPDATE', ['id' => (int) $room['id']]);
                $db->query(
                    "SELECT id FROM reservations
                     WHERE room_id = :rid AND status IN ('pending_payment', 'confirmed')
                       AND starts_at < :end AND ends_at > :start
                     FOR UPDATE",
                    [
                        'rid' => (int) $room['id'],
                        'start' => $startUtc->modify('-1 hour')->format('Y-m-d H:i:s'),
                        'end' => $endUtc->modify('+1 hour')->format('Y-m-d H:i:s'),
                    ]
                );
                $occupied = array_values(array_filter(
                    $this->occupiedIntervals((int) $room['id'], $startUtc->modify('-6 hours')->format('Y-m-d H:i:s'), $endUtc->modify('+6 hours')->format('Y-m-d H:i:s')),
                    static fn (array $row): bool => !in_array((int) ($row['id'] ?? 0), $ignoreReservationIds, true)
                ));
                if (!$this->isValidStart($open, $startLocal, $occupied, $durationStep + $buffer)) {
                    throw new HttpException(422, 'Začátek musí být v bloku 1 h 15 min, nebo hned po předchozím termínu.');
                }
                if ($this->overlaps($occupied, $startUtc, $endUtc, $buffer)) {
                    throw new HttpException(409, 'Tento termín je již obsazený.');
                }

                $status = $useMembership ? 'confirmed' : 'pending_payment';
                $membershipId = $useMembership ? (int) $membership['id'] : null;
                if ($useMembership && $membership['entries_remaining'] !== null) {
                    $this->memberships->consumeEntry((int) $membership['id'], (int) $user['id'], $blocks);
                }

                $id = (int) $db->insert('reservations', [
                    'public_id' => Crypto::uuid(),
                    'room_id' => (int) $room['id'],
                    'user_id' => (int) $user['id'],
                    'membership_id' => $membershipId,
                    'status' => $status,
                    'starts_at' => $startUtc->format('Y-m-d H:i:s'),
                    'ends_at' => $endUtc->format('Y-m-d H:i:s'),
                    'buffer_minutes' => $buffer,
                    'guest_count' => $guestCount,
                    'price' => $useMembership ? 0 : $price,
                    'currency' => 'CZK',
                    'created_at' => Clock::utc(),
                    'updated_at' => Clock::utc(),
                ]);
                $this->claimOccupancy($db, (int) $room['id'], $startUtc, $endUtc, $buffer, $id);

                $door = $db->fetch('SELECT id FROM doors WHERE room_id = :rid AND is_active = 1 LIMIT 1', ['rid' => (int) $room['id']]);
                if ($door && $status === 'confirmed') {
                    $early = $this->settings->int('access.early_minutes', 5);
                    try {
                        $db->insert('access_permissions', [
                            'user_id' => (int) $user['id'],
                            'door_id' => (int) $door['id'],
                            'reservation_id' => $id,
                            'valid_from' => $startUtc->modify('-' . $early . ' minutes')->format('Y-m-d H:i:s'),
                            'valid_until' => $endUtc->modify('+' . $buffer . ' minutes')->format('Y-m-d H:i:s'),
                            'created_at' => Clock::utc(),
                        ]);
                    } catch (\Throwable) {
                        // rezervace platí i bez záznamu ke dveřím
                    }
                }

                $reservation = $db->fetch('SELECT * FROM reservations WHERE id = :id', ['id' => $id]);
                if ($status === 'confirmed') {
                    try {
                        $this->mail->queue('reservation-confirmed', $user['email'], [
                            'subject' => 'Potvrzení rezervace PRIVOFIT',
                            'first_name' => $user['first_name'],
                            'starts_at' => Clock::format($reservation['starts_at']),
                            'ends_at' => Clock::format($reservation['ends_at']),
                        ], (int) $user['id']);
                    } catch (\Throwable) {
                        // rezervace platí i bez e-mailu
                    }
                }
                return $reservation;
            });
            $this->touchLive();
            if (is_array($reservation) && ($reservation['status'] ?? '') === 'confirmed') {
                (new ReservationAdminNotice($this->db))->send((int) $reservation['id']);
            }
            return $reservation;
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new HttpException(409, 'Tento termín je již obsazený.');
            }
            throw $e;
        } finally {
            try {
                $this->db->query("SELECT RELEASE_LOCK('{$lockName}')");
            } catch (\Throwable) {
            }
        }
    }

    public function cancel(array $user, string $publicId, bool $admin = false, ?string $reason = null): string
    {
        $reservation = $this->owned($user, $publicId, $admin);
        if (($reservation['status'] ?? '') === 'cancelled') {
            return 'unpaid';
        }
        if (!in_array($reservation['status'], ['pending_payment', 'confirmed'], true)) {
            throw new HttpException(422, 'Tuto rezervaci nelze zrušit.');
        }
        $starts = new \DateTimeImmutable($reservation['starts_at'], new \DateTimeZone('UTC'));
        if (!$admin && $starts <= Clock::nowUtc()) {
            throw new HttpException(422, 'Termín už začal, nelze ho zrušit.');
        }
        $reason = trim((string) $reason);
        if ($reason !== '') {
            $reason = mb_substr($reason, 0, 255);
        }
        $money = $this->settleMoney($reservation, $admin);
        $this->db->update('reservations', [
            'status' => 'cancelled',
            'cancellation_reason' => $reason !== '' ? $reason : null,
            'cancelled_at' => Clock::utc(),
            'cancelled_by' => (int) $user['id'],
            'updated_at' => Clock::utc(),
        ], 'id = :id', ['id' => (int) $reservation['id']]);
        $this->releaseOccupancy((int) $reservation['id']);
        $restored = 0;
        if (!empty($reservation['membership_id']) && (float) ($reservation['price'] ?? 0) <= 0) {
            try {
                $restored = $this->memberships->restoreEntry(
                    (int) $reservation['membership_id'],
                    (int) $user['id'],
                    $this->entryBlocksFromReservation($reservation)
                );
            } catch (\Throwable) {
            }
        }
        try {
            $this->db->query('DELETE FROM access_permissions WHERE reservation_id = :id', ['id' => (int) $reservation['id']]);
        } catch (\Throwable) {
        }
        try {
            $owner = $this->db->fetch(
                'SELECT email, first_name FROM users WHERE id = :id',
                ['id' => (int) $reservation['user_id']]
            );
            $recipient = is_array($owner) ? ($owner['email'] ?? null) : null;
            if (!is_string($recipient) || $recipient === '') {
                $recipient = $user['email'] ?? null;
            }
            $firstName = is_array($owner) ? (string) ($owner['first_name'] ?? '') : (string) ($user['first_name'] ?? '');
            if (is_string($recipient) && $recipient !== '') {
                $this->mail->queue('reservation-cancelled', $recipient, [
                    'subject' => 'Zrušení rezervace PRIVOFIT',
                    'first_name' => $firstName,
                    'starts_at' => Clock::format($reservation['starts_at']),
                    'reason' => $reason,
                ], (int) $reservation['user_id']);
            }
        } catch (\Throwable) {
            // zrušení platí i bez e-mailu
        }
        $this->touchLive();
        if ($money === 'entry' && $restored > 1) {
            return 'entries';
        }
        if ($money === 'entry' && $restored === 0) {
            return 'unpaid';
        }
        return $money;
    }

    /** @param array<string, mixed> $reservation */
    private function settleMoney(array $reservation, bool $forceRefund = false): string
    {
        if ((float) ($reservation['price'] ?? 0) <= 0) {
            return !empty($reservation['membership_id']) ? 'entry' : 'unpaid';
        }
        $payment = $this->db->fetch(
            "SELECT * FROM payments WHERE reservation_id = :id AND status IN ('paid', 'refunded') ORDER BY id DESC LIMIT 1",
            ['id' => (int) $reservation['id']]
        );
        if ($payment && ($payment['status'] ?? '') === 'refunded') {
            return 'refunded';
        }
        if (!$payment || ($payment['status'] ?? '') !== 'paid' || empty($payment['paid_at'])) {
            return 'unpaid';
        }
        $paidAt = new \DateTimeImmutable((string) $payment['paid_at'], new \DateTimeZone('UTC'));
        if (!$forceRefund && Clock::nowUtc()->getTimestamp() > $paidAt->getTimestamp() + self::REFUND_SECONDS) {
            return 'late';
        }
        $reference = trim((string) ($payment['provider_reference'] ?? ''));
        if ($reference === '' || (($payment['provider'] ?? '') !== 'stripe')) {
            throw new HttpException(422, 'Peníze se teď nepodařilo vrátit. Termín zůstává.');
        }
        StripeGateway::fromConfig()->refundPayment($reference, (string) $payment['public_id'] . ':refund');
        (new PaymentService($this->db))->markRefunded((int) $payment['id']);
        return 'refunded';
    }

    public function find(string $publicId): ?array
    {
        return $this->db->fetch('SELECT * FROM reservations WHERE public_id = :pid', ['pid' => $publicId]);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM reservations WHERE id = :id', ['id' => $id]);
    }

    public function owned(array $user, string $publicId, bool $admin = false): array
    {
        $reservation = $this->db->fetch('SELECT * FROM reservations WHERE public_id = :pid', ['pid' => $publicId]);
        if (!$reservation) {
            throw new HttpException(404, 'Rezervace nebyla nalezena.');
        }
        if (!$admin && (int) $reservation['user_id'] !== (int) $user['id']) {
            throw new HttpException(403, 'K této rezervaci nemáte přístup.');
        }
        return $reservation;
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT r.*, rm.name AS room_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.user_id = :uid AND r.status <> 'expired'
             ORDER BY r.starts_at DESC",
            ['uid' => $userId]
        );
    }

    public function upcoming(int $userId): ?array
    {
        return $this->db->fetch(
            "SELECT r.*, rm.name AS room_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.user_id = :uid AND r.status = 'confirmed' AND r.ends_at >= :now
             ORDER BY r.starts_at ASC LIMIT 1",
            ['uid' => $userId, 'now' => Clock::utc()]
        );
    }

    /** @return list<array<string, mixed>> */
    public function upcomingList(int $userId, int $limit = 4): array
    {
        $limit = max(1, min(10, $limit));
        return $this->db->fetchAll(
            "SELECT r.*, rm.name AS room_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.user_id = :uid AND r.status IN ('confirmed', 'pending_payment') AND r.ends_at >= :now
             ORDER BY r.starts_at ASC
             LIMIT {$limit}",
            ['uid' => $userId, 'now' => Clock::utc()]
        );
    }

    /**
     * Streak = po sobě jdoucí týdny (Po–Ne) s alespoň jedním dokončeným tréninkem.
     * Aktuální týden bez tréninku streak nepřeruší — počítá se od minulého.
     *
     * @return array{
     *   streak:int,
     *   best_streak:int,
     *   month_count:int,
     *   total_count:int,
     *   year_count:int,
     *   history:list<array{label:string,count:int,active:bool}>
     * }
     */
    public function trainingStats(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT starts_at
             FROM reservations
             WHERE user_id = :uid AND status = 'confirmed' AND ends_at < :now
             ORDER BY starts_at DESC",
            ['uid' => $userId, 'now' => Clock::utc()]
        );

        $tz = new \DateTimeZone(Clock::displayTimezone());
        $weeks = [];
        $weekCounts = [];
        $days = [];
        foreach ($rows as $row) {
            $local = Clock::toLocal((string) $row['starts_at']);
            $day = $local->format('Y-m-d');
            $days[$day] = true;
            $monday = $local->modify('-' . ((int) $local->format('N') - 1) . ' days')->setTime(0, 0);
            $weekKey = $monday->format('Y-m-d');
            $weeks[$weekKey] = true;
            $weekCounts[$weekKey] = ($weekCounts[$weekKey] ?? 0) + 1;
        }

        $now = Clock::nowLocal();
        $monthPrefix = $now->format('Y-m');
        $yearPrefix = $now->format('Y');
        $monthCount = 0;
        $yearCount = 0;
        foreach (array_keys($days) as $day) {
            if (str_starts_with($day, $monthPrefix)) {
                $monthCount++;
            }
            if (str_starts_with($day, $yearPrefix)) {
                $yearCount++;
            }
        }

        $cursor = $now->modify('-' . ((int) $now->format('N') - 1) . ' days')->setTime(0, 0);
        if (!isset($weeks[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->modify('-7 days');
        }
        $streak = 0;
        while (isset($weeks[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-7 days');
        }

        $best = 0;
        $run = 0;
        $sorted = array_keys($weeks);
        sort($sorted);
        $prev = null;
        foreach ($sorted as $monday) {
            if ($prev !== null) {
                $expected = (new \DateTimeImmutable($prev, $tz))->modify('+7 days')->format('Y-m-d');
                $run = $monday === $expected ? $run + 1 : 1;
            } else {
                $run = 1;
            }
            $best = max($best, $run);
            $prev = $monday;
        }

        $history = [];
        $thisMonday = $now->modify('-' . ((int) $now->format('N') - 1) . ' days')->setTime(0, 0);
        for ($i = 11; $i >= 0; $i--) {
            $monday = $thisMonday->modify('-' . ($i * 7) . ' days');
            $key = $monday->format('Y-m-d');
            $history[] = [
                'label' => $monday->format('j.n.'),
                'count' => (int) ($weekCounts[$key] ?? 0),
                'active' => isset($weeks[$key]),
            ];
        }

        return [
            'streak' => $streak,
            'best_streak' => max($best, $streak),
            'month_count' => $monthCount,
            'year_count' => $yearCount,
            'total_count' => count($days),
            'history' => $history,
        ];
    }

    public function current(int $userId): ?array
    {
        $early = $this->settings->int('access.early_minutes', 5);
        return $this->db->fetch(
            "SELECT r.*, rm.name AS room_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.user_id = :uid AND r.status = 'confirmed'
               AND DATE_SUB(r.starts_at, INTERVAL {$early} MINUTE) <= :now
               AND DATE_ADD(r.ends_at, INTERVAL COALESCE(r.buffer_minutes, 0) MINUTE) >= :now2
             ORDER BY r.starts_at ASC LIMIT 1",
            ['uid' => $userId, 'now' => Clock::utc(), 'now2' => Clock::utc()]
        );
    }

    /** @return list<int> Identifikátory rezervací, kterým právě vypršela nezaplacená blokace. */
    public function expireHolds(): array
    {
        $minutes = $this->settings->int('reservation.hold_minutes', 40);
        $limit = Clock::nowUtc()->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT id FROM reservations WHERE status = 'pending_payment' AND created_at < :limit",
            ['limit' => $limit]
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $this->db->update('reservations', ['status' => 'expired'], 'id = :id', ['id' => $id]);
            $this->releaseOccupancy($id);
            $ids[] = $id;
        }
        $this->db->query(
            "UPDATE reservations SET status = 'completed'
             WHERE status = 'confirmed'
               AND DATE_ADD(ends_at, INTERVAL COALESCE(buffer_minutes, 0) MINUTE) < :now",
            ['now' => Clock::utc()]
        );
        if ($ids !== []) {
            $this->touchLive();
        }
        return $ids;
    }

    public function room(?int $roomId = null): array
    {
        $room = $roomId
            ? $this->db->fetch('SELECT * FROM rooms WHERE id = :id AND is_active = 1', ['id' => $roomId])
            : $this->db->fetch('SELECT * FROM rooms WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        if (!$room) {
            throw new HttpException(404, 'Prostor nebyl nalezen.');
        }
        return $room;
    }

    /** @return list<array<string, mixed>> */
    public function activeRooms(): array
    {
        return $this->db->fetchAll(
            'SELECT id, public_id, name, location, description, max_persons
             FROM rooms WHERE is_active = 1 ORDER BY name ASC, id ASC'
        );
    }

    public function roomByPublicId(string $publicId): ?array
    {
        if ($publicId === '') {
            return null;
        }
        $room = $this->db->fetch(
            'SELECT * FROM rooms WHERE public_id = :pid AND is_active = 1',
            ['pid' => $publicId]
        );
        return $room ?: null;
    }

    public function occupancyNow(): array
    {
        $room = $this->room();
        $current = $this->db->fetch(
            "SELECT r.*, u.first_name, u.last_name
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.room_id = :rid AND r.status = 'confirmed' AND r.starts_at <= :now AND r.ends_at >= :now2
             LIMIT 1",
            ['rid' => (int) $room['id'], 'now' => Clock::utc(), 'now2' => Clock::utc()]
        );
        return [
            'occupied' => $current !== null,
            'reservation' => $current,
        ];
    }

    /**
     * @return array{state:string,label:string,detail:string,dot:string,room:string,opens_at:?string,closes_at:?string,next_at:?string}
     */
    public function studioStatus(int $soonMinutes = 60): array
    {
        $room = $this->room();
        $local = Clock::nowLocal();
        $hours = $this->hoursForDate($room, $local);
        $roomName = (string) ($room['name'] ?? 'Studio');

        $base = [
            'room' => $roomName,
            'opens_at' => null,
            'closes_at' => null,
            'next_at' => null,
        ];

        if (!empty($hours['closed'])) {
            return array_merge($base, [
                'state' => 'closed',
                'dot' => 'red',
                'label' => 'Uzavřené',
                'detail' => $roomName . ' má dnes zavřeno. Nový termín si můžeš vybrat na jindy.',
            ]);
        }

        $opensAt = (string) ($hours['opens_at'] ?? '00:00:00');
        $closesAt = (string) ($hours['closes_at'] ?? '23:59:00');
        $openLocal = Clock::parseLocal($local->format('Y-m-d') . ' ' . $opensAt);
        $closeLocal = Clock::parseLocal($local->format('Y-m-d') . ' ' . $closesAt);
        $base['opens_at'] = substr($opensAt, 0, 5);
        $base['closes_at'] = substr($closesAt, 0, 5);

        if ($local < $openLocal || $local >= $closeLocal) {
            $when = $local < $openLocal
                ? 'Otevře se dnes v ' . $base['opens_at'] . '.'
                : 'Dnes už je po otevírací době. Zítra od ' . $base['opens_at'] . '.';
            return array_merge($base, [
                'state' => 'closed',
                'dot' => 'red',
                'label' => 'Uzavřené',
                'detail' => $roomName . ' je teď zavřené. ' . $when,
            ]);
        }

        $now = Clock::utc();
        $current = $this->db->fetch(
            "SELECT r.starts_at, r.ends_at, r.buffer_minutes
             FROM reservations r
             WHERE r.room_id = :rid AND r.status = 'confirmed' AND r.starts_at <= :now AND r.ends_at >= :now2
             LIMIT 1",
            ['rid' => (int) $room['id'], 'now' => $now, 'now2' => $now]
        );
        if ($current) {
            $until = Clock::format((string) $current['ends_at'], 'H:i');
            return array_merge($base, [
                'state' => 'soon',
                'dot' => 'orange',
                'label' => 'Probíhá termín',
                'detail' => $roomName . ' je právě obsazené. Trénink končí v ' . $until . '.',
                'next_at' => $until,
            ]);
        }

        $horizon = Clock::nowUtc()->modify('+' . max(1, $soonMinutes) . ' minutes')->format('Y-m-d H:i:s');
        $next = $this->db->fetch(
            "SELECT starts_at, ends_at
             FROM reservations
             WHERE room_id = :rid AND status = 'confirmed' AND starts_at > :now AND starts_at <= :until
             ORDER BY starts_at ASC
             LIMIT 1",
            ['rid' => (int) $room['id'], 'now' => $now, 'until' => $horizon]
        );
        if ($next) {
            $start = Clock::format((string) $next['starts_at'], 'H:i');
            return array_merge($base, [
                'state' => 'soon',
                'dot' => 'orange',
                'label' => 'Blíží se termín',
                'detail' => 'Další rezervace začíná v ' . $start . '. Do té doby je prostor ještě volný.',
                'next_at' => $start,
            ]);
        }

        return array_merge($base, [
            'state' => 'open',
            'dot' => 'green',
            'label' => 'Volné',
            'detail' => $roomName . ' je teď volné. Otevřeno do ' . $base['closes_at'] . '.',
        ]);
    }

    private function hoursForDate(array $room, \DateTimeImmutable $localDay): array
    {
        $date = $localDay->format('Y-m-d');
        $exception = $this->db->fetch(
            'SELECT * FROM opening_hour_exceptions WHERE room_id = :rid AND exception_date = :d',
            ['rid' => (int) $room['id'], 'd' => $date]
        );
        $weekday = (int) $localDay->format('N');
        $hours = $this->db->fetch(
            'SELECT * FROM opening_hours WHERE room_id = :rid AND weekday = :w',
            ['rid' => (int) $room['id'], 'w' => $weekday]
        );
        $hourlyPrice = $hours['hourly_price'] ?? null;

        if ($exception) {
            return [
                'closed' => (int) $exception['is_closed'] === 1,
                'opens_at' => $exception['opens_at'] ?? '06:00:00',
                'closes_at' => $exception['closes_at'] ?? '22:00:00',
                'hourly_price' => $hourlyPrice,
            ];
        }
        if (!$hours || (int) $hours['is_closed'] === 1) {
            return ['closed' => true, 'opens_at' => '00:00:00', 'closes_at' => '00:00:00', 'hourly_price' => $hourlyPrice];
        }
        return [
            'closed' => false,
            'opens_at' => $hours['opens_at'],
            'closes_at' => $hours['closes_at'],
            'hourly_price' => $hourlyPrice,
        ];
    }

    private function occupiedIntervals(int $roomId, string $from, string $to): array
    {
        $reservations = $this->db->fetchAll(
            "SELECT id, user_id, starts_at, ends_at, buffer_minutes
             FROM reservations
             WHERE room_id = :rid AND status IN ('pending_payment', 'confirmed')
               AND starts_at < :to AND ends_at > :from",
            ['rid' => $roomId, 'from' => $from, 'to' => $to]
        );
        return $reservations;
    }

    /** @return array<string, true> */
    private function ownedDates(int $roomId, string $from, string $to, ?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT starts_at FROM reservations
             WHERE room_id = :rid AND user_id = :uid
               AND status IN ('pending_payment', 'confirmed')
               AND starts_at < :to AND ends_at > :from",
            ['rid' => $roomId, 'uid' => $userId, 'from' => $from, 'to' => $to]
        );
        $dates = [];
        foreach ($rows as $row) {
            $dates[Clock::toLocal((string) $row['starts_at'])->format('Y-m-d')] = true;
        }
        return $dates;
    }

    /** @param list<array<string, mixed>> $occupied */
    private function overlapsOwned(array $occupied, \DateTimeImmutable $start, \DateTimeImmutable $end, int $userId): bool
    {
        foreach ($occupied as $item) {
            if ((int) ($item['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $existingStart = new \DateTimeImmutable((string) $item['starts_at'], new \DateTimeZone('UTC'));
            $existingEnd = new \DateTimeImmutable((string) $item['ends_at'], new \DateTimeZone('UTC'));
            if ($start < $existingEnd && $end > $existingStart) {
                return true;
            }
        }
        return false;
    }

    private function overlaps(array $occupied, \DateTimeImmutable $start, \DateTimeImmutable $end, int $buffer): bool
    {
        foreach ($occupied as $item) {
            $existingStart = new \DateTimeImmutable($item['starts_at'], new \DateTimeZone('UTC'));
            $existingEnd = (new \DateTimeImmutable($item['ends_at'], new \DateTimeZone('UTC')))
                ->modify('+' . (int) ($item['buffer_minutes'] ?? 0) . ' minutes');
            $newEnd = $end->modify('+' . $buffer . ' minutes');
            if ($start < $existingEnd && $newEnd > $existingStart) {
                return true;
            }
        }
        return false;
    }

    private function overlapsBody(array $occupied, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        foreach ($occupied as $item) {
            $existingStart = new \DateTimeImmutable((string) $item['starts_at'], new \DateTimeZone('UTC'));
            $existingEnd = new \DateTimeImmutable((string) $item['ends_at'], new \DateTimeZone('UTC'));
            if ($start < $existingEnd && $end > $existingStart) {
                return true;
            }
        }
        return false;
    }

    private function ensureOccupancyTable(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS reservation_occupancy (
                    room_id BIGINT UNSIGNED NOT NULL,
                    starts_at DATETIME NOT NULL,
                    reservation_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (room_id, starts_at),
                    KEY idx_reservation_occupancy_reservation (reservation_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable) {
        }
    }

    private function occupancyTicks(\DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, int $buffer): array
    {
        $step = 15;
        $until = $endUtc->modify('+' . $buffer . ' minutes');
        $ticks = [];
        for ($cursor = $startUtc; $cursor < $until; $cursor = $cursor->modify('+' . $step . ' minutes')) {
            $ticks[] = $cursor->format('Y-m-d H:i:s');
        }
        return $ticks;
    }

    private function claimOccupancy(
        Database $db,
        int $roomId,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        int $buffer,
        int $reservationId,
    ): void {
        foreach ($this->occupancyTicks($startUtc, $endUtc, $buffer) as $tick) {
            try {
                $db->insert('reservation_occupancy', [
                    'room_id' => $roomId,
                    'starts_at' => $tick,
                    'reservation_id' => $reservationId,
                ]);
            } catch (\PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    throw new HttpException(409, 'Tento termín je již obsazený.');
                }
                throw $e;
            }
        }
    }

    private function releaseOccupancy(int $reservationId): void
    {
        try {
            $this->db->query('DELETE FROM reservation_occupancy WHERE reservation_id = :id', ['id' => $reservationId]);
        } catch (\Throwable) {
        }
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driver = (int) ($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' || $driver === 1062;
    }

    public function slotPrice(?\DateTimeImmutable $localStart = null, int $guestCount = 1): string
    {
        $hourly = null;
        if ($localStart !== null) {
            $hourly = $this->hourlyFromHours($this->hoursForDate($this->room(), $localStart));
        }
        return $this->priceForDuration($this->settings->int('reservation.min_minutes', 60), $hourly, $guestCount);
    }

    /** @return list<array{id:string,name:string,address:string,latitude:float,longitude:float}> */
    public function gymsForApp(): array
    {
        $hasCoordinates = $this->db->fetchAll("SHOW COLUMNS FROM rooms LIKE 'latitude'") !== [];
        $columns = $hasCoordinates ? 'public_id, name, location, latitude, longitude' : 'public_id, name, location';
        $rows = $this->db->fetchAll(
            "SELECT {$columns} FROM rooms WHERE is_active = 1 ORDER BY name ASC, id ASC"
        );
        $items = [];
        foreach ($rows as $room) {
            $latitude = isset($room['latitude']) && is_numeric($room['latitude']) ? (float) $room['latitude'] : 0.0;
            $longitude = isset($room['longitude']) && is_numeric($room['longitude']) ? (float) $room['longitude'] : 0.0;
            $items[] = [
                'id' => (string) $room['public_id'],
                'name' => (string) $room['name'],
                'address' => (string) ($room['location'] ?? ''),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }
        return $items;
    }

    /** @return list<array{id:string,start:string,end:string,room:string,bufferMinutes:int,price:string,currencyCode:string,gymID:string}> */
    public function availableSlotsForApp(int $days = 14, string $gymPublicId = '', ?int $userId = null): array
    {
        if ($gymPublicId !== '') {
            $room = $this->roomByPublicId($gymPublicId);
            if (!$room) {
                return [];
            }
        } else {
            try {
                $room = $this->room();
            } catch (HttpException) {
                return [];
            }
        }
        $out = [];
        $day = Clock::nowLocal()->setTime(0, 0);
        for ($i = 0; $i < $days; $i++) {
            $date = $day->modify('+' . $i . ' days')->format('Y-m-d');
            $availability = $this->availability($date, (int) $room['id'], $userId);
            if (!empty($availability['closed'])) {
                continue;
            }
            foreach ($availability['slots'] as $slot) {
                $mine = !empty($slot['mine']);
                if (($slot['kind'] ?? '') === 'buffer' || ($slot['kind'] ?? '') === 'past') {
                    continue;
                }
                if (empty($slot['available']) && !$mine) {
                    continue;
                }
                $start = new \DateTimeImmutable((string) $slot['start_at'], new \DateTimeZone('UTC'));
                $payload = $this->slotAppPayload($room['public_id'] . '_' . $start->format('YmdHis'), $start, (string) $room['name'], (string) $room['public_id'], $room);
                $payload['mine'] = $mine;
                $out[] = $payload;
            }
        }
        return $out;
    }

    /** @param list<string> $slotIds @return list<array{id:string,start:string,end:string,room:string}> */
    public function resolveSlotIds(array $slotIds): array
    {
        if ($slotIds === []) {
            throw new HttpException(422, 'Vyberte alespoň jeden termín.');
        }
        $resolved = [];
        foreach ($slotIds as $id) {
            $resolved[] = $this->decodeSlotId($id);
        }
        return $resolved;
    }

    public function createFromSlotId(array $user, string $slotId, bool $paidCheckout, array $ignoreReservationIds = [], int $guestCount = 1): array
    {
        $created = $this->createFromSlotIds($user, [$slotId], $paidCheckout, $ignoreReservationIds, $guestCount);
        return $created[0];
    }

    /**
     * @param list<string> $slotIds
     * @param list<int> $ignoreReservationIds
     * @return list<array<string, mixed>>
     */
    public function createFromSlotIds(array $user, array $slotIds, bool $paidCheckout, array $ignoreReservationIds = [], int $guestCount = 1): array
    {
        $groups = $this->groupConsecutiveSlots($this->resolveSlotIds($slotIds));
        $created = [];
        $ignore = $ignoreReservationIds;
        foreach ($groups as $group) {
            $room = $this->db->fetch('SELECT * FROM rooms WHERE public_id = :pid AND is_active = 1', [
                'pid' => $this->roomPublicIdFromSlot($group['id']),
            ]);
            $reservation = $this->create(
                $user,
                $group['local_start'],
                $group['duration'],
                max(1, $guestCount),
                $room ? (int) $room['id'] : null,
                $paidCheckout,
                $ignore
            );
            $ignore[] = (int) $reservation['id'];
            $created[] = $reservation;
        }
        return $created;
    }

    /**
     * @param list<array{id:string,start:string,end:string,room:string}> $slots
     * @return list<array{id:string,local_start:string,duration:int,slots:list<array{id:string,start:string,end:string,room:string}>}>
     */
    public function groupConsecutiveSlots(array $slots): array
    {
        if ($slots === []) {
            throw new HttpException(422, 'Vyberte alespoň jeden termín.');
        }
        usort($slots, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $buffer = $this->bufferMinutes();
        $groups = [];
        $current = null;
        foreach ($slots as $slot) {
            $start = (new \DateTimeImmutable($slot['start']))->setTimezone(new \DateTimeZone('UTC'));
            $end = (new \DateTimeImmutable($slot['end']))->setTimezone(new \DateTimeZone('UTC'));
            $gap = (int) ($slot['bufferMinutes'] ?? $buffer);
            $occupiedEnd = $end->modify('+' . $gap . ' minutes');
            if ($current !== null && $current['occupied_end'] === $start->format('Y-m-d H:i:s')) {
                $current['end_utc'] = $end->format('Y-m-d H:i:s');
                $current['occupied_end'] = $occupiedEnd->format('Y-m-d H:i:s');
                $current['duration'] += (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                $current['slots'][] = $slot;
                continue;
            }
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = [
                'id' => $slot['id'],
                'local_start' => Clock::toLocal($start->format('Y-m-d H:i:s'))->format('Y-m-d H:i:s'),
                'end_utc' => $end->format('Y-m-d H:i:s'),
                'occupied_end' => $occupiedEnd->format('Y-m-d H:i:s'),
                'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
                'slots' => [$slot],
            ];
        }
        if ($current !== null) {
            $groups[] = $current;
        }
        return $groups;
    }

    /** @param list<string> $slotIds */
    public function releasePendingForSlots(array $user, array $slotIds): void
    {
        foreach ($slotIds as $slotId) {
            try {
                $slot = $this->decodeSlotId((string) $slotId);
            } catch (HttpException) {
                continue;
            }
            $start = (new \DateTimeImmutable($slot['start'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->update('reservations', [
                'status' => 'expired',
                'updated_at' => Clock::utc(),
            ], "user_id = :uid AND status = 'pending_payment' AND starts_at = :start", [
                'uid' => (int) $user['id'],
                'start' => $start,
            ]);
            $held = $this->db->fetchAll(
                "SELECT id FROM reservations WHERE user_id = :uid AND status = 'expired' AND starts_at = :start",
                ['uid' => (int) $user['id'], 'start' => $start]
            );
            foreach ($held as $row) {
                $this->releaseOccupancy((int) $row['id']);
            }
        }
        $this->touchLive();
    }

    public function confirmPending(array $reservation, array $user): array
    {
        $wasPending = ($reservation['status'] ?? '') === 'pending_payment';
        if ($wasPending) {
            $this->db->update('reservations', [
                'status' => 'confirmed',
                'updated_at' => Clock::utc(),
            ], 'id = :id AND status = :pending', [
                'id' => (int) $reservation['id'],
                'pending' => 'pending_payment',
            ]);
        }
        $fresh = $this->db->fetch('SELECT r.*, rm.name AS room_name FROM reservations r INNER JOIN rooms rm ON rm.id = r.room_id WHERE r.id = :id', [
            'id' => (int) $reservation['id'],
        ]);
        if (!$fresh) {
            return $reservation;
        }
        $door = $this->db->fetch('SELECT id FROM doors WHERE room_id = :rid AND is_active = 1 LIMIT 1', ['rid' => (int) $fresh['room_id']]);
        if ($door) {
            $early = $this->settings->int('access.early_minutes', 5);
            $buffer = max(0, (int) ($fresh['buffer_minutes'] ?? 0));
            $startUtc = new \DateTimeImmutable($fresh['starts_at'], new \DateTimeZone('UTC'));
            $endUtc = new \DateTimeImmutable($fresh['ends_at'], new \DateTimeZone('UTC'));
            $exists = $this->db->fetch('SELECT id FROM access_permissions WHERE reservation_id = :id', ['id' => (int) $fresh['id']]);
            if (!$exists) {
                try {
                    $this->db->insert('access_permissions', [
                        'user_id' => (int) $user['id'],
                        'door_id' => (int) $door['id'],
                        'reservation_id' => (int) $fresh['id'],
                        'valid_from' => $startUtc->modify('-' . $early . ' minutes')->format('Y-m-d H:i:s'),
                        'valid_until' => $endUtc->modify('+' . $buffer . ' minutes')->format('Y-m-d H:i:s'),
                        'created_at' => Clock::utc(),
                    ]);
                } catch (\Throwable) {
                    // rezervace platí i bez záznamu ke dveřím
                }
            }
        }
        try {
            if ($wasPending) {
                $this->mail->queue('reservation-confirmed', $user['email'], [
                    'subject' => 'Potvrzení rezervace PRIVOFIT',
                    'first_name' => $user['first_name'],
                    'starts_at' => Clock::format($fresh['starts_at']),
                    'ends_at' => Clock::format($fresh['ends_at']),
                ], (int) $user['id']);
            }
        } catch (\Throwable) {
            // rezervace platí i bez e-mailu
        }
        if ($wasPending && ($fresh['status'] ?? '') === 'confirmed') {
            (new ReservationAdminNotice($this->db))->send((int) $fresh['id']);
        }
        $this->touchLive();
        return $fresh;
    }

    public function failPending(array $reservation): void
    {
        if (($reservation['status'] ?? '') !== 'pending_payment') {
            return;
        }
        $this->db->update('reservations', [
            'status' => 'expired',
            'updated_at' => Clock::utc(),
        ], 'id = :id AND status = :pending', [
            'id' => (int) $reservation['id'],
            'pending' => 'pending_payment',
        ]);
        $this->releaseOccupancy((int) $reservation['id']);
        $this->touchLive();
    }

    /** @return array{id:string,start:string,end:string,room:string,bufferMinutes:int,price:string,currencyCode:string} */
    private function decodeSlotId(string $slotId): array
    {
        if (!preg_match('/^([0-9a-f-]{36})_(\d{14})$/i', $slotId, $match)) {
            throw new HttpException(422, 'Neplatný termín.');
        }
        $room = $this->db->fetch('SELECT * FROM rooms WHERE public_id = :pid AND is_active = 1', ['pid' => $match[1]]);
        if (!$room) {
            throw new HttpException(422, 'Prostor termínu nebyl nalezen.');
        }
        $start = \DateTimeImmutable::createFromFormat('YmdHis', $match[2], new \DateTimeZone('UTC'));
        if (!$start) {
            throw new HttpException(422, 'Neplatný čas termínu.');
        }
        return $this->slotAppPayload($slotId, $start, (string) $room['name'], (string) $room['public_id'], $room);
    }

    /**
     * @param array<string, mixed>|null $room
     * @return array{id:string,start:string,end:string,room:string,bufferMinutes:int,price:string,priceTwo:string,maxPersons:int,mine:bool,currencyCode:string,gymID:string}
     */
    private function slotAppPayload(string $slotId, \DateTimeImmutable $startUtc, string $roomName, string $gymId = '', ?array $room = null): array
    {
        $startUtc = $startUtc->setTimezone(new \DateTimeZone('UTC'));
        $duration = $this->settings->int('reservation.min_minutes', 60);
        $end = $startUtc->modify('+' . $duration . ' minutes');
        $local = Clock::toLocal($startUtc->format('Y-m-d H:i:s'));
        $gym = $gymId !== '' ? $gymId : $this->roomPublicIdFromSlot($slotId);
        if ($room === null && $gym !== '') {
            $room = $this->roomByPublicId($gym);
        }
        $hourly = null;
        if (is_array($room)) {
            $hourly = $this->hourlyFromHours($this->hoursForDate($room, $local));
        }
        $maxPersons = max(1, (int) ($room['max_persons'] ?? 2));
        $priceOne = $this->priceForDuration($duration, $hourly, 1);
        $priceTwo = $this->priceForDuration($duration, $hourly, min(2, $maxPersons));
        return [
            'id' => $slotId,
            'start' => Clock::iso($startUtc->format('Y-m-d H:i:s')),
            'end' => Clock::iso($end->format('Y-m-d H:i:s')),
            'room' => $roomName,
            'bufferMinutes' => $this->bufferMinutes(),
            'price' => $priceOne,
            'priceTwo' => $priceTwo,
            'maxPersons' => $maxPersons,
            'mine' => false,
            'currencyCode' => 'CZK',
            'gymID' => $gym,
        ];
    }

    private function roomPublicIdFromSlot(string $slotId): string
    {
        return substr($slotId, 0, 36);
    }

    private function slotMinutes(): int
    {
        return max(1, $this->settings->int('reservation.slot_minutes', 15));
    }

    private function bufferMinutes(): int
    {
        return max(0, $this->settings->int('reservation.buffer_minutes', 15));
    }

    private function durationStep(): int
    {
        return 60;
    }

    /** Nejdelší trénink, který se vejde do otevírací doby a do nastaveného stropu. */
    private function allowedTrainingMinutes(\DateTimeImmutable $open, \DateTimeImmutable $close): int
    {
        $step = max(1, $this->durationStep());
        $block = $step + $this->bufferMinutes();
        if ($block < 1) {
            $block = $step;
        }
        $span = max(0, (int) round(($close->getTimestamp() - $open->getTimestamp()) / 60));
        $blocks = intdiv($span, $block);
        $dayMax = max($step, $blocks * $step);
        $configured = $this->settings->int('reservation.max_minutes', $dayMax);
        if ($configured < $step) {
            return $dayMax;
        }
        return min($configured, $dayMax);
    }

    private function blocksPhrase(int $blocks): string
    {
        if ($blocks === 1) {
            return '1 blok';
        }
        if ($blocks >= 2 && $blocks <= 4) {
            return $blocks . ' bloky';
        }
        return $blocks . ' bloků';
    }

    /** Kolik vstupů rezervace sebrala: jeden blok = jeden vstup. */
    private function entryBlocksFromReservation(array $reservation): int
    {
        $start = new \DateTimeImmutable((string) $reservation['starts_at'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable((string) $reservation['ends_at'], new \DateTimeZone('UTC'));
        $train = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        $buffer = (int) ($reservation['buffer_minutes'] ?? $this->bufferMinutes());
        $block = $this->durationStep() + max(0, $buffer);
        if ($block < 1) {
            return 1;
        }
        return max(1, (int) round(($train + max(0, $buffer)) / $block));
    }

    /** Kolik celých bloků (1 h + úklid) rezervace zabere. Dva a tři bloky se nesčítají do jednoho úklidu. */
    private function spanMinutes(int $durationMinutes): int
    {
        $blocks = max(1, intdiv($durationMinutes, $this->durationStep()));
        return $blocks * ($this->durationStep() + $this->bufferMinutes());
    }

    /**
     * Začátky po blocích (hodina tréninku + úklid) od otevíračky a hned po skončení obsazenosti.
     *
     * @param list<array<string, mixed>> $occupied
     * @return list<\DateTimeImmutable>
     */
    private function startCandidates(\DateTimeImmutable $open, \DateTimeImmutable $close, array $occupied, int $hourMinutes): array
    {
        $candidates = [];
        for ($cursor = $open; $cursor < $close; $cursor = $cursor->modify('+' . $hourMinutes . ' minutes')) {
            $candidates[$cursor->format('Y-m-d H:i:s')] = $cursor;
        }
        foreach ($occupied as $item) {
            $endUtc = new \DateTimeImmutable((string) $item['ends_at'], new \DateTimeZone('UTC'));
            $gap = (int) ($item['buffer_minutes'] ?? 0);
            $next = Clock::toLocal($endUtc->modify('+' . $gap . ' minutes')->format('Y-m-d H:i:s'));
            if ($next >= $open && $next < $close) {
                $candidates[$next->format('Y-m-d H:i:s')] = $next;
            }
        }
        ksort($candidates);
        return array_values($candidates);
    }

    /** @param list<array<string, mixed>> $occupied */
    private function isValidStart(\DateTimeImmutable $open, \DateTimeImmutable $startLocal, array $occupied, int $hourMinutes): bool
    {
        $delta = (int) floor(($startLocal->getTimestamp() - $open->getTimestamp()) / 60);
        if ($delta >= 0 && $delta % $hourMinutes === 0) {
            return true;
        }
        foreach ($occupied as $item) {
            $endUtc = new \DateTimeImmutable((string) $item['ends_at'], new \DateTimeZone('UTC'));
            $gap = (int) ($item['buffer_minutes'] ?? 0);
            $next = Clock::toLocal($endUtc->modify('+' . $gap . ' minutes')->format('Y-m-d H:i:s'));
            if ($next->format('Y-m-d H:i') === $startLocal->format('Y-m-d H:i')) {
                return true;
            }
        }
        return false;
    }

    private function hourlyFromHours(array $hours): float
    {
        $override = $hours['hourly_price'] ?? null;
        if ($override !== null && $override !== '' && is_numeric($override)) {
            return (float) $override;
        }
        return (float) $this->settings->get('pricing.hourly', 150);
    }

    /** 1 osoba = základní hodinovka, 2 osoby = základní + příplatek (výchozí 150 → 200). */
    private function rateForGuests(int $guestCount, float $hourlyOne): float
    {
        if ($guestCount < 2) {
            return $hourlyOne;
        }
        $one = (float) $this->settings->get('pricing.hourly', 150);
        $two = (float) $this->settings->get('pricing.hourly_two', 200);
        return $hourlyOne + max(0.0, $two - $one);
    }

    private function priceForDuration(int $minutes, ?float $hourlyOverride = null, int $guestCount = 1): string
    {
        $hourlyOne = $hourlyOverride ?? (float) $this->settings->get('pricing.hourly', 150);
        $rate = $this->rateForGuests($guestCount, $hourlyOne);
        $hours = max(1, (int) ceil($minutes / 60));
        return number_format($rate * $hours, 2, '.', '');
    }

    private function touchLive(): void
    {
        try {
            $this->settings->bumpLive();
        } catch (\Throwable) {
        }
    }
}
