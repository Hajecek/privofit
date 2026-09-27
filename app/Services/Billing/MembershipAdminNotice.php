<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;

/** Okamžitá zpráva administrátorům k zaplacení, zrušení nebo odmítnutí tarifu. */
final class MembershipAdminNotice
{
    public function __construct(private readonly Database $db)
    {
    }

    public function send(int $paymentId, string $kind): void
    {
        if ($paymentId < 1 || !in_array($kind, ['paid', 'cancelled', 'declined'], true)) {
            return;
        }
        try {
            $row = $this->db->fetch(
                'SELECT p.id, p.status, p.amount, p.charged_amount, p.currency,
                        u.first_name, u.last_name, pl.name AS plan_name
                 FROM payments p
                 INNER JOIN memberships m ON m.id = p.membership_id
                 INNER JOIN membership_plans pl ON pl.id = m.plan_id
                 LEFT JOIN users u ON u.id = p.user_id
                 WHERE p.id = :id AND p.membership_id IS NOT NULL',
                ['id' => $paymentId]
            );
            if (!$row) {
                return;
            }
            if ($kind !== 'paid' && (string) $row['status'] === 'paid') {
                return;
            }
            $plan = (string) ($row['plan_name'] ?: 'tarif');
            $person = CronText::person($row);
            $money = $this->money($row);
            $detail = $person . ' · ' . $plan . ' · ' . $money;
            [$subject, $body] = match ($kind) {
                'paid' => ['💳 Tarif zaplacen', $detail],
                'cancelled' => ['🚫 Platba zrušena', $detail],
                default => ['⚠️ Platba odmítnuta', $detail],
            };
            NotificationDispatcher::make($this->db)->notifyNow(
                'admin:membership.' . $kind . ':' . $paymentId,
                'admin',
                'membership.' . $kind,
                null,
                [
                    'template' => 'admin-membership',
                    'push_type' => 'admin.sync',
                    'subject' => $subject,
                    'body' => $body,
                    'action_url' => CronText::link('/user/sprava/trzby'),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Zpráva administrátorům o tarifu se neodeslala', [
                'payment' => $paymentId,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function money(array $row): string
    {
        $charged = (float) ($row['charged_amount'] ?? 0);
        $amount = $charged > 0 ? $charged : (float) ($row['amount'] ?? 0);
        $currency = strtoupper((string) ($row['currency'] ?? 'CZK'));
        $formatted = number_format($amount, 2, ',', ' ');
        return $currency === 'CZK' ? $formatted . ' Kč' : $formatted . ' ' . $currency;
    }
}
