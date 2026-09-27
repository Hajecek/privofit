<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;

final class OpsAlertJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
    ) {
    }

    public function name(): string
    {
        return 'ops-alerts';
    }

    public function run(): array
    {
        return [
            'payments' => $this->failedPayments(),
            'contacts' => $this->pendingContacts(),
        ];
    }

    private function failedPayments(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.user_id, p.amount, p.currency, u.first_name, u.last_name
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status = 'failed'
               AND p.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
        $scheduled = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $amount = trim(((string) $row['amount']) . ' ' . ((string) ($row['currency'] ?: 'CZK')));
            $person = CronText::person($row);
            $userOk = false;
            if ((int) ($row['user_id'] ?? 0) > 0) {
                $userOk = $this->notify->schedule(
                    'user:payment.failed:' . $id,
                    'user',
                    'payment.failed',
                    (int) $row['user_id'],
                    [
                        'template' => 'payment-failed',
                        'push_type' => 'payment.sync',
                        'subject' => 'Platba se nezdařila',
                        'body' => 'Platbu ' . $amount . ' se nepodařilo dokončit. Termín ani členství se bez ní neaktivují.',
                        'action_url' => CronText::link('/user/moje-rezervace'),
                    ]
                );
            }
            $adminOk = $this->notify->schedule(
                'admin:payment.failed:' . $id,
                'admin',
                'payment.failed',
                null,
                [
                    'template' => 'admin-payment',
                    'push_type' => 'admin.sync',
                    'subject' => 'Neúspěšná platba',
                    'body' => 'Platba ' . $amount . ' zákazníka ' . $person . ' se nezdařila.',
                    'action_url' => CronText::link('/user/sprava'),
                ]
            );
            if ($userOk || $adminOk) {
                $scheduled++;
            }
        }
        return $scheduled;
    }

    private function pendingContacts(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, email, message
             FROM contact_messages
             WHERE handled_at IS NULL
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
        $scheduled = 0;
        foreach ($rows as $row) {
            $excerpt = trim(preg_replace('/\s+/', ' ', (string) $row['message']) ?? '');
            if (mb_strlen($excerpt) > 180) {
                $excerpt = mb_substr($excerpt, 0, 177) . '…';
            }
            $ok = $this->notify->schedule(
                'admin:contact.pending:' . (int) $row['id'],
                'admin',
                'contact.pending',
                null,
                [
                    'template' => 'admin-contact',
                    'push_type' => 'admin.sync',
                    'subject' => 'Nová zpráva z webu',
                    'body' => trim((string) $row['name']) . ' (' . trim((string) $row['email']) . ') posílá zprávu: ' . $excerpt,
                    'action_url' => CronText::link('/user/sprava'),
                ]
            );
            if ($ok) {
                $scheduled++;
            }
        }
        return $scheduled;
    }
}
