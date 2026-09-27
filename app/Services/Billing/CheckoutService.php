<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Application;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Auth\AuthService;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Services\MembershipService;
use App\Services\ReservationService;
use App\Support\Clock;

final class CheckoutService
{
    public function __construct(
        private readonly Database $db,
        private readonly PaymentService $payments,
        private readonly ReservationService $reservations,
    ) {
    }

    public static function make(Database $db): self
    {
        return new self($db, new PaymentService($db), ReservationService::make($db));
    }

    public function start(array $user, array $reservation, Application $app): string
    {
        $amount = number_format((float) ($reservation['price'] ?? 0), 2, '.', '');
        if ((float) $amount <= 0) {
            throw new HttpException(422, 'Tuto rezervaci není potřeba platit.');
        }
        $priced = StripeFee::cover($amount);
        try {
            StripeGateway::fromConfig();
        } catch (HttpException $e) {
            $this->reservations->failPending($reservation);
            throw $e;
        }
        $payment = $this->payments->createStripeHold($user, $priced['net'], (int) $reservation['id'], $priced['fee'], $priced['charge']);
        return $app->absoluteUrl('/user/platba/' . rawurlencode((string) $payment['public_id']));
    }

    public function startMembership(array $user, array $membership, Application $app): string
    {
        $amount = number_format((float) ($membership['price'] ?? 0), 2, '.', '');
        if ((float) $amount <= 0) {
            throw new HttpException(422, 'Tenhle tarif nemá cenu k zaplacení.');
        }
        $priced = StripeFee::cover($amount);
        try {
            StripeGateway::fromConfig();
        } catch (HttpException $e) {
            (new MembershipService($this->db))->cancelPending((int) $membership['id'], (int) $user['id']);
            throw $e;
        }
        $payment = $this->payments->createStripeMembership($user, $priced['net'], (int) $membership['id'], $priced['fee'], $priced['charge']);
        return $app->absoluteUrl('/user/platba/' . rawurlencode((string) $payment['public_id']));
    }

    public function fulfillSession(string $sessionId): array
    {
        $session = StripeGateway::fromConfig()->retrieveCheckoutSession($sessionId);
        $paid = ($session['payment_status'] ?? '') === 'paid' || ($session['status'] ?? '') === 'complete';
        if (!$paid) {
            throw new HttpException(402, 'Platba ještě neprošla.');
        }
        return $this->completeFromSession($session, (string) ($session['payment_intent'] ?? $sessionId));
    }

    /** @param array<string, mixed> $event */
    public function handleWebhook(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $sessionId = (string) ($object['id'] ?? '');
        if ($type === 'payment_intent.succeeded') {
            $this->completeFromIntent($object);
            return;
        }
        if ($type === 'payment_intent.payment_failed') {
            $this->noteMembershipFromIntent($object, 'declined');
            return;
        }
        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            if (($object['payment_status'] ?? '') === 'paid' || ($object['status'] ?? '') === 'complete') {
                $this->completeFromSession($object, (string) ($object['payment_intent'] ?? $sessionId));
            }
            return;
        }
        if ($type === 'checkout.session.expired' || $type === 'checkout.session.async_payment_failed') {
            $payment = $this->paymentFromSession($object);
            if ($payment && $payment['status'] !== 'paid' && $payment['reservation_id']) {
                $reservation = $this->reservations->findById((int) $payment['reservation_id']);
                if ($reservation) {
                    $this->reservations->failPending($reservation);
                }
            }
            if ($payment && !empty($payment['membership_id'])) {
                $kind = $type === 'checkout.session.async_payment_failed' ? 'declined' : 'cancelled';
                if ($kind === 'cancelled') {
                    $this->cancelMembershipPayment($payment);
                }
                (new MembershipAdminNotice($this->db))->send((int) $payment['id'], $kind);
            }
        }
    }

    public function cancelHold(string $paymentPublicId, array $user): void
    {
        $payment = $this->payments->findByPublicId($paymentPublicId);
        if (!$payment || (int) $payment['user_id'] !== (int) $user['id']) {
            return;
        }
        if ($payment['status'] === 'paid') {
            return;
        }
        if ($payment['reservation_id']) {
            $reservation = $this->reservations->findById((int) $payment['reservation_id']);
            if ($reservation && (int) $reservation['user_id'] === (int) $user['id']) {
                $this->reservations->failPending($reservation);
            }
        }
        if (!empty($payment['membership_id'])) {
            $this->cancelMembershipPayment($payment);
            (new MembershipAdminNotice($this->db))->send((int) $payment['id'], 'cancelled');
        }
    }

    public function noteMembershipDeclined(string $paymentPublicId, array $user): void
    {
        $payment = $this->payments->findByPublicId(trim($paymentPublicId));
        if (!$payment || (int) ($payment['user_id'] ?? 0) !== (int) $user['id'] || empty($payment['membership_id'])) {
            return;
        }
        (new MembershipAdminNotice($this->db))->send((int) $payment['id'], 'declined');
    }

    /** @param array<string, mixed> $session */
    private function completeFromSession(array $session, string $reference): array
    {
        $payment = $this->paymentFromSession($session);
        if (!$payment) {
            throw new HttpException(404, 'Platba k této relaci nebyla nalezena.');
        }
        $stripeRef = $reference !== '' ? $reference : (string) ($session['id'] ?? '');
        return $this->finish($payment, $stripeRef);
    }

    /** @return array<string, mixed> */
    public function page(string $publicId, array $user): array
    {
        $payment = $this->ownedPayment($publicId, $user);
        if ($payment['status'] === 'paid') {
            return ['payment' => $payment, 'paid' => true];
        }
        if ($payment['status'] !== 'pending') {
            throw new HttpException(422, 'Tuhle platbu už nejde dokončit.');
        }
        $net = number_format((float) ($payment['amount'] ?? 0), 2, '.', '');
        $variants = StripeFee::variants($net);
        $summary = $this->summary($payment);
        return [
            'payment' => $payment,
            'paid' => false,
            'summary' => $summary,
            'variants' => $variants,
            'shown' => $variants['eea'],
            'publishableKey' => trim((string) env_value('STRIPE_PUBLISHABLE_KEY', '')),
            'cancelUrl' => $summary['cancelUrl'],
        ];
    }

    /**
     * Když zobrazená částka nesedí na kartu, vrátí novou cenu a nic nestrhne.
     *
     * @return array<string, mixed>
     */
    public function confirm(array $user, string $publicId, string $confirmationTokenId, int $shownMinor, Application $app): array
    {
        $payment = $this->ownedPayment($publicId, $user);
        if ($payment['status'] === 'paid') {
            return [
                'ready' => true,
                'status' => 'succeeded',
                'intentId' => (string) ($payment['provider_reference'] ?? ''),
                'returnUrl' => $this->returnUrl($app, (string) $payment['public_id']),
            ];
        }
        if ($payment['status'] !== 'pending') {
            throw new HttpException(422, 'Tuhle platbu už nejde dokončit.');
        }
        $gateway = StripeGateway::fromConfig();
        $token = $gateway->retrieveConfirmationToken($confirmationTokenId);
        $preview = is_array($token['payment_method_preview'] ?? null) ? $token['payment_method_preview'] : [];
        $net = number_format((float) ($payment['amount'] ?? 0), 2, '.', '');
        $inspected = StripeFee::inspectMethod($preview);
        $priced = StripeFee::coverFor($net, $inspected['country']);
        $quote = $this->publicQuote($priced, $inspected);
        if ($shownMinor !== $priced['chargeMinor']) {
            return ['ready' => false] + $quote;
        }
        $summary = $this->summary($payment);
        $returnUrl = $this->returnUrl($app, (string) $payment['public_id']);
        $this->payments->applyStripeQuote((int) $payment['id'], $priced);
        $existingId = (string) ($payment['provider_reference'] ?? '');
        if (str_starts_with($existingId, 'pi_')) {
            $previous = $gateway->retrievePaymentIntent($existingId);
            $previousStatus = (string) ($previous['status'] ?? '');
            if ($previousStatus === 'succeeded') {
                $this->finish($payment, $existingId);
                return [
                    'ready' => true,
                    'status' => 'succeeded',
                    'intentId' => $existingId,
                    'clientSecret' => '',
                    'returnUrl' => $returnUrl,
                ] + $quote;
            }
            if ($previousStatus === 'processing') {
                throw new HttpException(402, 'Platba se dokončuje. Počkej chvíli a obnov stránku.');
            }
            if (in_array($previousStatus, ['requires_action', 'requires_confirmation', 'requires_payment_method'], true)) {
                $gateway->cancelPaymentIntent($existingId);
            }
        }
        try {
            $intent = $gateway->chargePaymentMethod(
                $confirmationTokenId,
                $priced['chargeMinor'],
                'czk',
                (string) $payment['public_id'] . ':' . $confirmationTokenId . ':' . $priced['chargeMinor'],
                $summary['description'],
                $returnUrl,
                [
                    'payment' => (string) $payment['public_id'],
                    'user' => (string) ($user['public_id'] ?? $user['id']),
                    'net' => $priced['net'],
                    'fee' => $priced['fee'],
                    'card_country' => $inspected['country'],
                ],
                trim((string) ($user['email'] ?? '')),
            );
        } catch (HttpException $e) {
            if ($e->status === 402 && !empty($payment['membership_id'])) {
                (new MembershipAdminNotice($this->db))->send((int) $payment['id'], 'declined');
            }
            throw $e;
        }
        if ($intent['id'] !== '') {
            $this->payments->attachProviderReference((int) $payment['id'], $intent['id']);
        }
        if ($intent['status'] === 'succeeded') {
            $this->finish($payment, $intent['id']);
        }
        return [
            'ready' => true,
            'status' => $intent['status'],
            'intentId' => $intent['id'],
            'clientSecret' => $intent['status'] === 'requires_action' ? $intent['client_secret'] : '',
            'returnUrl' => $returnUrl,
        ] + $quote;
    }

    public function fulfillIntent(string $intentId, array $user): array
    {
        $intent = StripeGateway::fromConfig()->retrievePaymentIntent($intentId);
        if ((string) ($intent['status'] ?? '') !== 'succeeded') {
            throw new HttpException(402, 'Platba ještě neprošla.');
        }
        $publicId = (string) (($intent['metadata']['payment'] ?? null) ?: '');
        $payment = $publicId !== '' ? $this->payments->findByPublicId($publicId) : null;
        if (!$payment) {
            $payment = $this->payments->findByProviderReference($intentId);
        }
        if (!$payment || (int) $payment['user_id'] !== (int) $user['id']) {
            throw new HttpException(403, 'Tato platba nepatří k tvému účtu.');
        }
        return $this->finish($payment, $intentId);
    }

    /** @param array<string, mixed> $intent */
    private function completeFromIntent(array $intent): void
    {
        if ((string) ($intent['status'] ?? '') !== 'succeeded') {
            return;
        }
        $intentId = (string) ($intent['id'] ?? '');
        $publicId = (string) (($intent['metadata']['payment'] ?? null) ?: '');
        $payment = $publicId !== '' ? $this->payments->findByPublicId($publicId) : null;
        if (!$payment && $intentId !== '') {
            $payment = $this->payments->findByProviderReference($intentId);
        }
        if (!$payment || $intentId === '') {
            return;
        }
        $this->finish($payment, $intentId);
    }

    /** @param array<string, mixed> $intent */
    private function noteMembershipFromIntent(array $intent, string $kind): void
    {
        $intentId = (string) ($intent['id'] ?? '');
        $publicId = (string) (($intent['metadata']['payment'] ?? null) ?: '');
        $payment = $publicId !== '' ? $this->payments->findByPublicId($publicId) : null;
        if (!$payment && $intentId !== '') {
            $payment = $this->payments->findByProviderReference($intentId);
        }
        if (!$payment || empty($payment['membership_id'])) {
            return;
        }
        (new MembershipAdminNotice($this->db))->send((int) $payment['id'], $kind);
    }

    /** @param array<string, mixed> $payment */
    private function cancelMembershipPayment(array $payment): void
    {
        $userId = (int) ($payment['user_id'] ?? 0);
        $membershipId = (int) ($payment['membership_id'] ?? 0);
        if ($userId < 1 || $membershipId < 1 || (string) ($payment['status'] ?? '') === 'paid') {
            return;
        }
        (new MembershipService($this->db))->cancelPending($membershipId, $userId);
    }

    /** @param array<string, mixed> $payment @return array<string, mixed> */
    private function finish(array $payment, string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new HttpException(502, 'Stripe nevrátil identifikátor platby.');
        }
        $this->payments->markPaid((int) $payment['id'], $reference, 'stripe:' . $reference);
        $this->payments->captureStripeFacts((int) $payment['id'], $reference);
        if (!empty($payment['membership_id'])) {
            (new MembershipService($this->db))->activatePurchase((int) $payment['membership_id']);
            (new MembershipAdminNotice($this->db))->send((int) $payment['id'], 'paid');
        }
        $reservation = $payment['reservation_id'] ? $this->reservations->findById((int) $payment['reservation_id']) : null;
        if (!$reservation) {
            return $this->payments->findByPublicId((string) $payment['public_id']) ?? $payment;
        }
        $user = AuthService::make($this->db)->findById((int) $reservation['user_id']);
        $this->reservations->confirmPending($reservation, $user ?: []);
        $this->notifyReservationPaid($payment, $reservation, $user ?: []);
        return $this->payments->findByPublicId((string) $payment['public_id']) ?? $payment;
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $user
     */
    private function notifyReservationPaid(array $payment, array $reservation, array $user): void
    {
        $person = CronText::person($user);
        $when = '';
        try {
            $when = Clock::format((string) $reservation['starts_at'], 'j. n. Y H:i');
        } catch (\Throwable) {
            $when = '';
        }
        $charged = (float) ($payment['charged_amount'] ?? 0);
        $amount = $charged > 0 ? $charged : (float) ($payment['amount'] ?? 0);
        $money = number_format($amount, 2, ',', ' ') . ' Kč';
        $parts = array_values(array_filter([$person, $when, $money], static fn (string $part): bool => $part !== ''));
        try {
            NotificationDispatcher::make($this->db)->notifyNow(
                'admin:reservation.paid:' . (int) $payment['id'],
                'admin',
                'reservation.paid',
                null,
                [
                    'template' => 'admin-reservation',
                    'push_type' => 'admin.sync',
                    'subject' => '🗓️ Rezervace zaplacena',
                    'body' => implode(' · ', $parts),
                    'action_url' => CronText::link('/user/sprava/rezervace'),
                ]
            );
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Zpráva administrátorům o rezervaci se neodeslala', [
                'payment' => $payment['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $payment @return array<string, mixed> */
    private function ownedPayment(string $publicId, array $user): array
    {
        $payment = $this->payments->findByPublicId(trim($publicId));
        if (!$payment || (int) $payment['user_id'] !== (int) $user['id']) {
            throw new HttpException(404, 'Platba nebyla nalezena.');
        }
        return $payment;
    }

    /** @param array<string, mixed> $payment @return array{title:string,description:string,cancelUrl:string,doneUrl:string,doneMessage:string} */
    private function summary(array $payment): array
    {
        if (!empty($payment['membership_id'])) {
            $membership = $this->db->fetch(
                'SELECT m.public_id, p.name AS plan_name, p.entries
                 FROM memberships m
                 INNER JOIN membership_plans p ON p.id = m.plan_id
                 WHERE m.id = :id',
                ['id' => (int) $payment['membership_id']]
            ) ?? [];
            $entries = ($membership['entries'] ?? null) === null ? 'neomezené vstupy' : ((int) $membership['entries'] . ' vstupů');
            $name = (string) ($membership['plan_name'] ?? 'členství');
            return [
                'title' => $name,
                'description' => 'PRIVOFIT ' . $name . ' · ' . $entries,
                'cancelUrl' => '/user/clenstvi/platba/zruseno?platba=' . rawurlencode((string) $payment['public_id']),
                'doneUrl' => '/user',
                'doneMessage' => 'Platba prošla. Členství je na účtu a vstupy můžeš čerpat rezervací dne.',
            ];
        }
        $reservation = $payment['reservation_id'] ? $this->reservations->findById((int) $payment['reservation_id']) : null;
        $when = $reservation ? Clock::format((string) $reservation['starts_at'], 'j. n. Y H:i') . '–' . Clock::format((string) $reservation['ends_at'], 'H:i') : '';
        $date = $reservation ? Clock::format((string) $reservation['starts_at'], 'Y-m-d') : '';
        $cancel = '/user/rezervace/platba/zruseno?platba=' . rawurlencode((string) $payment['public_id']);
        if ($date !== '') {
            $cancel .= '&date=' . rawurlencode($date);
        }
        return [
            'title' => $when !== '' ? 'Rezervace ' . $when : 'Rezervace',
            'description' => 'PRIVOFIT rezervace' . ($when !== '' ? ' ' . $when : ''),
            'cancelUrl' => $cancel,
            'doneUrl' => '/user/moje-rezervace',
            'doneMessage' => 'Platba prošla a rezervace je potvrzená.',
        ];
    }

    /**
     * @param array{net:string,fee:string,charge:string,netMinor:int,feeMinor:int,chargeMinor:int} $priced
     * @param array{country:string,assumed:bool,type:string} $inspected
     * @return array<string, mixed>
     */
    private function publicQuote(array $priced, array $inspected): array
    {
        return [
            'fee' => $priced['fee'],
            'charge' => $priced['charge'],
            'chargeMinor' => $priced['chargeMinor'],
            'feeMinor' => $priced['feeMinor'],
            'band' => StripeFee::band($inspected['country']),
            'label' => StripeFee::label($inspected['country']),
            'assumed' => $inspected['assumed'],
        ];
    }

    private function returnUrl(Application $app, string $publicId): string
    {
        return $app->absoluteUrl('/user/platba/navrat') . '?platba=' . rawurlencode($publicId);
    }

    public function destination(array $payment): array
    {
        return $this->summary($payment);
    }

    /** @param array<string, mixed> $session */
    private function paymentFromSession(array $session): ?array
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId !== '') {
            $payment = $this->payments->findByProviderReference($sessionId);
            if ($payment) {
                return $payment;
            }
        }
        $publicId = (string) (($session['metadata']['payment'] ?? null) ?: ($session['client_reference_id'] ?? ''));
        if ($publicId === '') {
            return null;
        }
        return $this->payments->findByPublicId($publicId);
    }
}
