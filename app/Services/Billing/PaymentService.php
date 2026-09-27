<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Support\Clock;

/**
 * Platební vrstva. Konkrétní brána se napojí až po výběru poskytovatele.
 * Přesměrování na success URL nikdy není důkazem platby.
 */
final class PaymentService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Sloupce, které vznikly později. Na produkci je stránka rezervací nesmí shodit,
     * když migrace ještě neproběhla.
     *
     * @return array<string, true>
     */
    public function paymentColumnSet(): array
    {
        $names = [];
        try {
            foreach ($this->db->fetchAll('SHOW COLUMNS FROM payments') as $column) {
                $names[(string) ($column['Field'] ?? '')] = true;
            }
        } catch (\Throwable) {
            return [];
        }

        $missing = [
            'fee_amount' => 'ALTER TABLE payments ADD COLUMN fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'charged_amount' => 'ALTER TABLE payments ADD COLUMN charged_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'stripe_details' => 'ALTER TABLE payments ADD COLUMN stripe_details MEDIUMTEXT DEFAULT NULL',
            'metadata_json' => 'ALTER TABLE payments ADD COLUMN metadata_json MEDIUMTEXT DEFAULT NULL',
        ];
        foreach ($missing as $name => $sql) {
            if ($name === '' || isset($names[$name])) {
                continue;
            }
            try {
                $this->db->query($sql);
                $names[$name] = true;
            } catch (\Throwable) {
            }
        }

        unset($names['']);
        return $names;
    }

    public function createManual(array $user, string $amount, ?int $reservationId = null, ?int $membershipId = null): array
    {
        $id = (int) $this->db->insert('payments', [
            'public_id' => Crypto::uuid(),
            'user_id' => (int) $user['id'],
            'reservation_id' => $reservationId,
            'membership_id' => $membershipId,
            'provider' => 'manual',
            'amount' => $amount,
            'currency' => 'CZK',
            'status' => 'pending',
            'created_at' => Clock::utc(),
            'updated_at' => Clock::utc(),
        ]);
        return $this->db->fetch('SELECT * FROM payments WHERE id = :id', ['id' => $id]) ?? [];
    }

    public function createStripeMembership(array $user, string $amount, int $membershipId, string $fee = '0.00', string $charged = ''): array
    {
        $id = (int) $this->db->insert('payments', [
            'public_id' => Crypto::uuid(),
            'user_id' => (int) $user['id'],
            'membership_id' => $membershipId,
            'provider' => 'stripe',
            'amount' => $amount,
            'fee_amount' => $fee,
            'charged_amount' => $charged !== '' ? $charged : $amount,
            'currency' => 'CZK',
            'status' => 'pending',
            'created_at' => Clock::utc(),
            'updated_at' => Clock::utc(),
        ]);
        return $this->db->fetch('SELECT * FROM payments WHERE id = :id', ['id' => $id]) ?? [];
    }

    public function createStripeHold(array $user, string $amount, int $reservationId, string $fee = '0.00', string $charged = ''): array
    {
        $id = (int) $this->db->insert('payments', [
            'public_id' => Crypto::uuid(),
            'user_id' => (int) $user['id'],
            'reservation_id' => $reservationId,
            'provider' => 'stripe',
            'amount' => $amount,
            'fee_amount' => $fee,
            'charged_amount' => $charged !== '' ? $charged : $amount,
            'currency' => 'CZK',
            'status' => 'pending',
            'created_at' => Clock::utc(),
            'updated_at' => Clock::utc(),
        ]);
        return $this->db->fetch('SELECT * FROM payments WHERE id = :id', ['id' => $id]) ?? [];
    }

    /** @param array{fee:string,charge:string} $priced */
    public function applyStripeQuote(int $paymentId, array $priced): void
    {
        $this->db->update('payments', [
            'fee_amount' => $priced['fee'],
            'charged_amount' => $priced['charge'],
            'updated_at' => Clock::utc(),
        ], 'id = :id AND status = :pending', [
            'id' => $paymentId,
            'pending' => 'pending',
        ]);
    }

    public function attachProviderReference(int $paymentId, string $reference): void
    {
        $this->db->update('payments', [
            'provider_reference' => $reference,
            'updated_at' => Clock::utc(),
        ], 'id = :id', ['id' => $paymentId]);
    }

    public function findByProviderReference(string $reference): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM payments WHERE provider = :p AND provider_reference = :r ORDER BY id DESC LIMIT 1',
            ['p' => 'stripe', 'r' => $reference]
        );
    }

    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->fetch('SELECT * FROM payments WHERE public_id = :pid', ['pid' => $publicId]);
    }

    /** @param array<string, mixed> $facts */
    public function rememberStripeFacts(int $paymentId, array $facts): void
    {
        if ($facts === []) {
            return;
        }
        $this->db->update('payments', [
            'stripe_details' => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'updated_at' => Clock::utc(),
        ], 'id = :id', ['id' => $paymentId]);
    }

    public function captureStripeFacts(int $paymentId, string $reference): void
    {
        try {
            $current = $this->db->fetch('SELECT stripe_details, provider FROM payments WHERE id = :id', ['id' => $paymentId]);
            if (!$current || ($current['provider'] ?? '') !== 'stripe' || trim((string) ($current['stripe_details'] ?? '')) !== '') {
                return;
            }
            $reference = trim($reference);
            if ($reference === '') {
                return;
            }
            $facts = StripeGateway::fromConfig()->paymentFacts($reference);
            $this->rememberStripeFacts($paymentId, $facts);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'No such')) {
                $this->rememberStripeFacts($paymentId, ['missing' => true]);
                return;
            }
            Logger::error('Detail platby ze Stripe se nepodařilo uložit', ['error' => $e->getMessage()]);
        }
    }

    /** @param list<array<string, mixed>> $payments @return list<array<string, mixed>> */
    public function withStripeFacts(array $payments): array
    {
        $fetched = 0;
        foreach ($payments as $index => $payment) {
            if ($fetched >= 20) {
                break;
            }
            if (($payment['provider'] ?? '') !== 'stripe' || trim((string) ($payment['stripe_details'] ?? '')) !== '') {
                continue;
            }
            $reference = trim((string) ($payment['provider_reference'] ?? ''));
            if ($reference === '' || empty($payment['id'])) {
                continue;
            }
            $fetched++;
            try {
                $this->captureStripeFacts((int) $payment['id'], $reference);
                $fresh = $this->db->fetch('SELECT stripe_details FROM payments WHERE id = :id', ['id' => (int) $payment['id']]);
                if ($fresh && trim((string) ($fresh['stripe_details'] ?? '')) !== '') {
                    $payments[$index]['stripe_details'] = $fresh['stripe_details'];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return $payments;
    }

    public function markPaid(int $paymentId, string $reference, string $idempotencyKey): void
    {
        $existing = $this->db->fetch(
            'SELECT id FROM payment_events WHERE idempotency_key = :k',
            ['k' => $idempotencyKey]
        );
        if ($existing) {
            return;
        }
        try {
            $this->db->transaction(function (Database $db) use ($paymentId, $reference, $idempotencyKey): void {
                $current = $db->fetch('SELECT provider_reference FROM payments WHERE id = :id', ['id' => $paymentId]);
                $kept = (string) ($current['provider_reference'] ?? '');
                $stored = (str_starts_with($kept, 'cs_') && !str_starts_with($reference, 'cs_')) ? $kept : $reference;
                $db->update('payments', [
                    'status' => 'paid',
                    'provider_reference' => $stored,
                    'paid_at' => Clock::utc(),
                ], 'id = :id', ['id' => $paymentId]);
                $db->insert('payment_events', [
                    'payment_id' => $paymentId,
                    'event_type' => 'paid',
                    'payload_hash' => Crypto::hash($reference . $idempotencyKey),
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => Clock::utc(),
                ]);
            });
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    public function markRefunded(int $paymentId): void
    {
        $this->db->update('payments', [
            'status' => 'refunded',
            'updated_at' => Clock::utc(),
        ], 'id = :id AND status = :paid', [
            'id' => $paymentId,
            'paid' => 'paid',
        ]);
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM payments WHERE user_id = :id ORDER BY created_at DESC',
            ['id' => $userId]
        );
    }

    /** Darované / nepeněžní platby se do tržeb nepočítají. */
    public static function countsAsRevenue(array $payment): bool
    {
        $amount = (float) ($payment['amount'] ?? 0);
        if ($amount <= 0) {
            return false;
        }
        $provider = strtolower((string) ($payment['provider'] ?? ''));
        if (in_array($provider, ['admin', 'membership', 'comp', 'gift'], true)) {
            return false;
        }
        return true;
    }

    /** SQL podmínka pro skutečné tržby (prefix sloupce např. "p."). */
    public static function revenueSql(string $alias = ''): string
    {
        $col = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return $col . "status = 'paid'
            AND " . $col . "amount > 0
            AND LOWER(" . $col . "provider) NOT IN ('admin', 'membership', 'comp', 'gift')
            AND (
                " . $col . "membership_id IS NULL
                OR NOT EXISTS (
                    SELECT 1 FROM membership_transactions mt
                    WHERE mt.membership_id = " . $col . "membership_id
                      AND mt.created_by IS NOT NULL
                      AND mt.type IN ('purchase', 'admin_adjust')
                )
            )";
    }

    public function recordAdminGrant(int $userId, int $membershipId): array
    {
        $now = Clock::utc();
        $id = (int) $this->db->insert('payments', [
            'public_id' => Crypto::uuid(),
            'user_id' => $userId,
            'membership_id' => $membershipId,
            'provider' => 'admin',
            'provider_reference' => 'grant',
            'amount' => '0.00',
            'currency' => 'CZK',
            'status' => 'paid',
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->db->fetch('SELECT * FROM payments WHERE id = :id', ['id' => $id]) ?? [];
    }

    public function cancelPendingMembershipPayments(int $userId): void
    {
        $this->db->query(
            "UPDATE payments
             SET status = 'cancelled', updated_at = :now
             WHERE user_id = :uid
               AND membership_id IS NOT NULL
               AND status IN ('pending', 'authorized')",
            ['uid' => $userId, 'now' => Clock::utc()]
        );
    }
}
