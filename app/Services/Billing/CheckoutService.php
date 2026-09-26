<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Application;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Auth\AuthService;
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
        $payment = $this->payments->createStripeHold($user, $priced['net'], (int) $reservation['id'], $priced['fee'], $priced['charge']);
        $localStart = Clock::format((string) $reservation['starts_at'], 'j. n. Y H:i');
        $localEnd = Clock::format((string) $reservation['ends_at'], 'H:i');
        $date = Clock::format((string) $reservation['starts_at'], 'Y-m-d');
        $success = $app->absoluteUrl('/user/rezervace/platba') . '?session_id={CHECKOUT_SESSION_ID}';
        $cancel = $app->absoluteUrl('/user/rezervace/platba/zruseno') . '?platba=' . rawurlencode((string) $payment['public_id']) . '&date=' . rawurlencode($date);
        $expires = time() + 35 * 60;
        try {
            $session = StripeGateway::fromConfig()->createCheckoutSession(
                $priced['netMinor'],
                'czk',
                'PRIVOFIT rezervace ' . $localStart . '–' . $localEnd,
                $success,
                $cancel,
                (string) $payment['public_id'],
                [
                    'payment' => (string) $payment['public_id'],
                    'reservation' => (string) $reservation['public_id'],
                    'user' => (string) ($user['public_id'] ?? $user['id']),
                    'net' => $priced['net'],
                    'fee' => $priced['fee'],
                ],
                $expires,
                $priced['feeMinor'],
            );
        } catch (HttpException $e) {
            $this->reservations->failPending($reservation);
            throw $e;
        }
        $this->payments->attachProviderReference((int) $payment['id'], $session['id']);
        return $session['url'];
    }

    public function startMembership(array $user, array $membership, Application $app): string
    {
        $amount = number_format((float) ($membership['price'] ?? 0), 2, '.', '');
        if ((float) $amount <= 0) {
            throw new HttpException(422, 'Tenhle tarif nemá cenu k zaplacení.');
        }
        $priced = StripeFee::cover($amount);
        $payment = $this->payments->createStripeMembership($user, $priced['net'], (int) $membership['id'], $priced['fee'], $priced['charge']);
        $entries = $membership['entries'] === null ? 'neomezené vstupy' : ((int) $membership['entries'] . ' vstupů');
        $success = $app->absoluteUrl('/user/clenstvi/platba') . '?session_id={CHECKOUT_SESSION_ID}';
        $cancel = $app->absoluteUrl('/user/clenstvi/platba/zruseno') . '?platba=' . rawurlencode((string) $payment['public_id']);
        try {
            $session = StripeGateway::fromConfig()->createCheckoutSession(
                $priced['netMinor'],
                'czk',
                'PRIVOFIT ' . (string) ($membership['plan_name'] ?? 'členství') . ' · ' . $entries,
                $success,
                $cancel,
                (string) $payment['public_id'],
                [
                    'payment' => (string) $payment['public_id'],
                    'membership' => (string) $membership['public_id'],
                    'user' => (string) ($user['public_id'] ?? $user['id']),
                    'net' => $priced['net'],
                    'fee' => $priced['fee'],
                ],
                time() + 35 * 60,
                $priced['feeMinor'],
            );
        } catch (HttpException $e) {
            (new MembershipService($this->db))->cancelPending((int) $membership['id'], (int) $user['id']);
            throw $e;
        }
        $this->payments->attachProviderReference((int) $payment['id'], $session['id']);
        return $session['url'];
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
            (new MembershipService($this->db))->cancelPending((int) $payment['membership_id'], (int) $user['id']);
        }
    }

    /** @param array<string, mixed> $session */
    private function completeFromSession(array $session, string $reference): array
    {
        $payment = $this->paymentFromSession($session);
        if (!$payment) {
            throw new HttpException(404, 'Platba k této relaci nebyla nalezena.');
        }
        $eventKey = 'stripe:' . ((string) ($session['id'] ?? $reference));
        $this->payments->markPaid((int) $payment['id'], $reference !== '' ? $reference : (string) $session['id'], $eventKey);
        $stripeRef = (string) ($session['id'] ?? '');
        if ($stripeRef === '') {
            $stripeRef = $reference;
        }
        $this->payments->captureStripeFacts((int) $payment['id'], $stripeRef);
        if (!empty($payment['membership_id'])) {
            (new MembershipService($this->db))->activatePurchase((int) $payment['membership_id']);
        }
        $reservation = $payment['reservation_id'] ? $this->reservations->findById((int) $payment['reservation_id']) : null;
        if (!$reservation) {
            return $this->payments->findByPublicId((string) $payment['public_id']) ?? $payment;
        }
        $user = AuthService::make($this->db)->findById((int) $reservation['user_id']);
        $this->reservations->confirmPending($reservation, $user);
        return $this->payments->findByPublicId((string) $payment['public_id']) ?? $payment;
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
