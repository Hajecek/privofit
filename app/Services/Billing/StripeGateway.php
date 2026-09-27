<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\HttpException;

/**
 * Stripe Test/Live přes REST. Tajný klíč zůstává na serveru.
 */
final class StripeGateway
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public static function fromConfig(): self
    {
        $key = trim((string) env_value('STRIPE_SECRET_KEY', ''));
        if ($key === '' || !str_starts_with($key, 'sk_')) {
            throw new HttpException(503, 'Stripe není nakonfigurovaný.');
        }
        return new self($key);
    }

    public function configured(): bool
    {
        return str_starts_with($this->secretKey, 'sk_');
    }

    /**
     * @param array<string, string> $metadata
     * @return array{id:string,url:string,status:string}
     */
    public function createCheckoutSession(
        int $amountMinor,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata = [],
        ?int $expiresAt = null,
        int $feeMinor = 0,
    ): array {
        $this->assertAmount($amountMinor);
        $fields = [
            'mode' => 'payment',
            'locale' => 'cs',
            'submit_type' => 'pay',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $metadata['payment'] ?? $idempotencyKey,
            'managed_payments[enabled]' => 'false',
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][unit_amount]' => (string) $amountMinor,
            'line_items[0][price_data][product_data][name]' => $description,
        ] + $this->metadataFields($metadata);
        if ($feeMinor > 0) {
            $fields['line_items[1][quantity]'] = '1';
            $fields['line_items[1][price_data][currency]'] = strtolower($currency);
            $fields['line_items[1][price_data][unit_amount]'] = (string) $feeMinor;
            $fields['line_items[1][price_data][product_data][name]'] = 'Poplatek za platbu kartou';
        }
        if ($expiresAt !== null) {
            $fields['expires_at'] = (string) $expiresAt;
        }
        $session = $this->request('POST', '/v1/checkout/sessions', $fields, $idempotencyKey . ':cs');
        $url = (string) ($session['url'] ?? '');
        $id = (string) ($session['id'] ?? '');
        if ($url === '' || $id === '') {
            throw new HttpException(502, 'Stripe Checkout se nepodařilo otevřít.');
        }
        return [
            'id' => $id,
            'url' => $url,
            'status' => (string) ($session['status'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !str_starts_with($sessionId, 'cs_')) {
            throw new HttpException(422, 'Neplatná platební relace.');
        }
        return $this->request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [], $sessionId . ':get');
    }

    public function refundPayment(string $reference, string $idempotencyKey): void
    {
        $reference = trim($reference);
        $intent = $reference;
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->retrieveCheckoutSession($reference);
            $intent = (string) ($session['payment_intent'] ?? '');
        }
        if (!str_starts_with($intent, 'pi_')) {
            throw new HttpException(422, 'Platbu teď nejde vrátit.');
        }
        $this->request('POST', '/v1/refunds', [
            'payment_intent' => $intent,
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function parseWebhook(string $payload, string $signatureHeader, string $secret): array
    {
        if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
            throw new HttpException(503, 'Stripe webhook není nakonfigurovaný.');
        }
        $parts = [];
        foreach (explode(',', $signatureHeader) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
            $parts[$key][] = $value;
        }
        $timestamp = (string) (($parts['t'][0] ?? ''));
        $signatures = $parts['v1'] ?? [];
        if ($timestamp === '' || $signatures === []) {
            throw new HttpException(400, 'Neplatný podpis Stripe.');
        }
        if (abs(time() - (int) $timestamp) > 300) {
            throw new HttpException(400, 'Podpis Stripe vypršel.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $ok = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new HttpException(400, 'Neplatný podpis Stripe.');
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new HttpException(400, 'Neplatné tělo webhooku.');
        }
        return $event;
    }

    /**
     * @return array{id:string,status:string}
     */
    /**
     * Částka je čistá cena vstupu. Poplatek se vezme podle země karty v Apple Pay.
     *
     * @param array<string, string> $metadata
     * @return array{id:string,status:string,fee:string,charge:string,net:string}
     */
    public function chargeApplePay(string $paymentDataJson, int $netMinor, string $currency, string $idempotencyKey, string $description, array $metadata = []): array
    {
        $this->assertAmount($netMinor);
        $token = $this->createApplePayToken($paymentDataJson, $idempotencyKey . ':token');
        $card = is_array($token['card'] ?? null) ? $token['card'] : [];
        $priced = StripeFee::coverFor(number_format($netMinor / 100, 2, '.', ''), (string) ($card['country'] ?? ''));
        $intent = $this->confirmIntent([
            'amount' => (string) $priced['chargeMinor'],
            'currency' => strtolower($currency),
            'confirm' => 'true',
            'off_session' => 'true',
            'confirmation_method' => 'automatic',
            'description' => $description,
            'payment_method_data[type]' => 'card',
            'payment_method_data[card][token]' => (string) ($token['id'] ?? ''),
        ] + $this->metadataFields($metadata), $idempotencyKey . ':pi');
        return $intent + [
            'fee' => $priced['fee'],
            'charge' => $priced['charge'],
            'net' => $priced['net'],
        ];
    }

    /**
     * Simulátor iOS nedoručí skutečný Apple Pay JSON. V APP_ENV=local + sk_test
     * se účtuje Stripe test karta, ne falešný úspěch bez Stripe.
     *
     * @return array{id:string,status:string}
     */
    /**
     * Testovací karta Stripe je zahraniční, proto sazba 3,15 % + 6,50 Kč.
     *
     * @param array<string, string> $metadata
     * @return array{id:string,status:string,fee:string,charge:string,net:string}
     */
    public function chargeTestCard(int $netMinor, string $currency, string $idempotencyKey, string $description, array $metadata = []): array
    {
        if (!str_starts_with($this->secretKey, 'sk_test_')) {
            throw new HttpException(422, 'Chybí Apple Pay token.');
        }
        $this->assertAmount($netMinor);
        $priced = StripeFee::coverFor(number_format($netMinor / 100, 2, '.', ''), 'US');
        $intent = $this->confirmIntent([
            'amount' => (string) $priced['chargeMinor'],
            'currency' => strtolower($currency),
            'confirm' => 'true',
            'off_session' => 'true',
            'confirmation_method' => 'automatic',
            'description' => $description,
            'payment_method' => 'pm_card_visa',
            'payment_method_types' => ['card'],
        ] + $this->metadataFields($metadata), $idempotencyKey . ':test-pi');
        return $intent + [
            'fee' => $priced['fee'],
            'charge' => $priced['charge'],
            'net' => $priced['net'],
        ];
    }

    /** @param array<string, string> $metadata @return array<string, string> */
    private function metadataFields(array $metadata): array
    {
        $fields = [];
        foreach ($metadata as $key => $value) {
            if ($value === '') {
                continue;
            }
            $fields['metadata[' . $key . ']'] = $value;
        }
        return $fields;
    }

    private function assertAmount(int $amountMinor): void
    {
        if ($amountMinor < 1) {
            throw new HttpException(422, 'Neplatná částka.');
        }
    }

    /** @param array<string, mixed> $fields @return array{id:string,status:string} */
    private function confirmIntent(array $fields, string $idempotencyKey): array
    {
        $intent = $this->request('POST', '/v1/payment_intents', $fields, $idempotencyKey);
        $status = (string) ($intent['status'] ?? '');
        if ($status !== 'succeeded') {
            throw new HttpException(402, 'Platba ve Stripe neprošla.');
        }
        return [
            'id' => (string) ($intent['id'] ?? ''),
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function createApplePayToken(string $paymentDataJson, string $idempotencyKey): array
    {
        $decoded = json_decode($paymentDataJson, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'Apple Pay token je neplatný.');
        }
        $token = $this->request('POST', '/v1/tokens', [
            'pk_token' => $paymentDataJson,
        ], $idempotencyKey);
        if ((string) ($token['id'] ?? '') === '') {
            throw new HttpException(402, 'Stripe token se nepodařilo vytvořit.');
        }
        return $token;
    }

    /** @return array<string, mixed> */
    public function retrieveConfirmationToken(string $tokenId): array
    {
        $tokenId = trim($tokenId);
        if (!str_starts_with($tokenId, 'ctoken_')) {
            throw new HttpException(422, 'Neplatná platební metoda.');
        }
        return $this->request('GET', '/v1/confirmation_tokens/' . rawurlencode($tokenId), [], $tokenId . ':get');
    }

    public function cancelPaymentIntent(string $intentId): void
    {
        $intentId = trim($intentId);
        if (!str_starts_with($intentId, 'pi_')) {
            return;
        }
        try {
            $this->request('POST', '/v1/payment_intents/' . rawurlencode($intentId) . '/cancel', [], $intentId . ':cancel');
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                throw $e;
            }
        }
    }

    /** @return array<string, mixed> */
    public function retrievePaymentIntent(string $intentId): array
    {
        $intentId = trim($intentId);
        if (!str_starts_with($intentId, 'pi_')) {
            throw new HttpException(422, 'Neplatná platba.');
        }
        return $this->request('GET', '/v1/payment_intents/' . rawurlencode($intentId), [], $intentId . ':get');
    }

    /**
     * Strhne částku, která už obsahuje poplatek podle země karty.
     *
     * @param array<string, string> $metadata
     * @return array{id:string,status:string,client_secret:string}
     */
    public function chargePaymentMethod(
        string $confirmationTokenId,
        int $chargeMinor,
        string $currency,
        string $idempotencyKey,
        string $description,
        string $returnUrl,
        array $metadata,
        string $receiptEmail = '',
    ): array {
        $this->assertAmount($chargeMinor);
        $confirmationTokenId = trim($confirmationTokenId);
        if (!str_starts_with($confirmationTokenId, 'ctoken_')) {
            throw new HttpException(422, 'Neplatná platební metoda.');
        }
        $fields = [
            'amount' => (string) $chargeMinor,
            'currency' => strtolower($currency),
            'confirm' => 'true',
            'confirmation_method' => 'automatic',
            'confirmation_token' => $confirmationTokenId,
            'return_url' => $returnUrl,
            'use_stripe_sdk' => 'true',
            'description' => $description,
        ] + $this->metadataFields($metadata);
        if ($receiptEmail !== '' && str_contains($receiptEmail, '@')) {
            $fields['receipt_email'] = $receiptEmail;
        }
        $intent = $this->request('POST', '/v1/payment_intents', $fields, $idempotencyKey . ':pi');
        $status = (string) ($intent['status'] ?? '');
        if (!in_array($status, ['succeeded', 'requires_action', 'processing'], true)) {
            throw new HttpException(402, 'Platba ve Stripe neprošla.');
        }
        return [
            'id' => (string) ($intent['id'] ?? ''),
            'status' => $status,
            'client_secret' => (string) ($intent['client_secret'] ?? ''),
        ];
    }

    /**
     * Metoda, stav, poplatek a výplata tak, jak je vrací Stripe.
     *
     * @return array<string, mixed>
     */
    public function paymentFacts(string $reference): array
    {
        $reference = trim($reference);
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->request('GET', '/v1/checkout/sessions/' . rawurlencode($reference), [
                'expand[0]' => 'payment_intent',
                'expand[1]' => 'payment_intent.latest_charge',
                'expand[2]' => 'payment_intent.latest_charge.balance_transaction',
            ], $reference . ':facts');
            $intent = $session['payment_intent'] ?? null;
            return is_array($intent) ? $this->factsFromIntent($intent) : [];
        }
        if (str_starts_with($reference, 'pi_')) {
            $intent = $this->request('GET', '/v1/payment_intents/' . rawurlencode($reference), [
                'expand[0]' => 'latest_charge',
                'expand[1]' => 'latest_charge.balance_transaction',
            ], $reference . ':facts');
            return $this->factsFromIntent($intent);
        }
        if (str_starts_with($reference, 'ch_')) {
            $charge = $this->request('GET', '/v1/charges/' . rawurlencode($reference), [
                'expand[0]' => 'balance_transaction',
            ], $reference . ':facts');
            return $this->factsFromCharge($charge, (string) ($charge['payment_intent'] ?? ''));
        }
        return [];
    }

    /** @param array<string, mixed> $intent @return array<string, mixed> */
    private function factsFromIntent(array $intent): array
    {
        $charge = $intent['latest_charge'] ?? null;
        if (is_string($charge) && str_starts_with($charge, 'ch_')) {
            $charge = $this->request('GET', '/v1/charges/' . rawurlencode($charge), [
                'expand[0]' => 'balance_transaction',
            ], $charge . ':facts');
        }
        if (!is_array($charge)) {
            return [
                'intent_id' => (string) ($intent['id'] ?? ''),
                'intent_status' => (string) ($intent['status'] ?? ''),
                'livemode' => (bool) ($intent['livemode'] ?? false),
            ];
        }
        return $this->factsFromCharge($charge, (string) ($intent['id'] ?? ''), (string) ($intent['status'] ?? ''));
    }

    /** @param array<string, mixed> $charge @return array<string, mixed> */
    private function factsFromCharge(array $charge, string $intentId, string $intentStatus = ''): array
    {
        $method = is_array($charge['payment_method_details'] ?? null) ? $charge['payment_method_details'] : [];
        $type = (string) ($method['type'] ?? '');
        $typed = is_array($method[$type] ?? null) ? $method[$type] : [];
        $wallet = is_array($typed['wallet'] ?? null) ? (string) ($typed['wallet']['type'] ?? '') : '';
        $balance = $charge['balance_transaction'] ?? null;
        if (is_string($balance) && str_starts_with($balance, 'txn_')) {
            try {
                $balance = $this->request('GET', '/v1/balance_transactions/' . rawurlencode($balance), [], $balance . ':get');
            } catch (HttpException) {
                $balance = null;
            }
        }
        $balance = is_array($balance) ? $balance : [];
        $outcome = is_array($charge['outcome'] ?? null) ? $charge['outcome'] : [];
        $fees = [];
        foreach (is_array($balance['fee_details'] ?? null) ? $balance['fee_details'] : [] as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $fees[] = [
                'amount' => $this->minorToMoney((int) ($fee['amount'] ?? 0)),
                'label' => (string) ($fee['description'] ?? ''),
                'type' => (string) ($fee['type'] ?? ''),
            ];
        }
        $availableOn = (int) ($balance['available_on'] ?? 0);

        return [
            'livemode' => (bool) ($charge['livemode'] ?? false),
            'intent_id' => $intentId !== '' ? $intentId : (string) ($charge['payment_intent'] ?? ''),
            'intent_status' => $intentStatus,
            'charge_id' => (string) ($charge['id'] ?? ''),
            'charge_status' => (string) ($charge['status'] ?? ''),
            'paid' => (bool) ($charge['paid'] ?? false),
            'refunded' => (bool) ($charge['refunded'] ?? false),
            'disputed' => (bool) ($charge['disputed'] ?? false),
            'amount_refunded' => $this->minorToMoney((int) ($charge['amount_refunded'] ?? 0)),
            'method' => $type,
            'wallet' => $wallet,
            'brand' => (string) ($typed['brand'] ?? ''),
            'last4' => (string) ($typed['last4'] ?? ''),
            'funding' => (string) ($typed['funding'] ?? ''),
            'country' => (string) ($typed['country'] ?? ''),
            'outcome' => (string) ($outcome['type'] ?? ''),
            'risk_level' => (string) ($outcome['risk_level'] ?? ''),
            'network_status' => (string) ($outcome['network_status'] ?? ''),
            'fee' => $this->minorToMoney((int) ($balance['fee'] ?? 0)),
            'net' => $this->minorToMoney((int) ($balance['net'] ?? 0)),
            'charged' => $this->minorToMoney((int) ($charge['amount'] ?? 0)),
            'currency' => strtoupper((string) ($charge['currency'] ?? 'CZK')),
            'balance_status' => (string) ($balance['status'] ?? ''),
            'available_on' => $availableOn > 0 ? gmdate('Y-m-d H:i:s', $availableOn) : '',
            'fees' => $fees,
            'receipt_url' => (string) ($charge['receipt_url'] ?? ''),
        ];
    }

    private function minorToMoney(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** @param array<string, mixed> $fields */
    private function request(string $method, string $path, array $fields, string $idempotencyKey): array
    {
        $url = 'https://api.stripe.com' . $path;
        if ($method === 'GET' && $fields !== []) {
            $url .= '?' . http_build_query($fields);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(502, 'Stripe je teď nedostupný.');
        }
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Idempotency-Key: ' . $idempotencyKey,
            'Stripe-Version: 2024-06-20',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            throw new HttpException(502, 'Stripe neodpověděl.');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new HttpException(502, 'Stripe poslal neplatnou odpověď.');
        }
        if ($status >= 400) {
            $message = (string) (($json['error']['message'] ?? null) ?: 'Platba ve Stripe selhala.');
            throw new HttpException($status >= 500 ? 502 : 402, $message);
        }
        return $json;
    }
}
