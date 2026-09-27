<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Billing\PaymentService;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Support\Clock;

/**
 * Souhrn dnešní tržby pro administrátory. Jen v aplikaci, bez e-mailu.
 * Částka se při každém běhu cronu znovu spočítá z aktuálních plateb.
 */
final class DailyRevenueJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function name(): string
    {
        return 'daily-revenue';
    }

    public function run(): array
    {
        $now = $this->now ?? Clock::nowUtc();
        $local = $now->setTimezone(new \DateTimeZone(Clock::displayTimezone()));
        $live = self::snapshot($this->db, $now);
        $payload = [
            'template' => 'admin-revenue',
            'skip_email' => true,
            'push_type' => 'admin.sync',
            'report_day' => $live['day'],
            'subject' => $live['subject'],
            'body' => $live['body'],
            'action_url' => CronText::link('/user/sprava'),
        ];
        $key = 'admin:revenue.today:' . $local->format('Y-m-d');
        $existing = $this->db->fetch('SELECT id, status FROM cron_events WHERE event_key = :key', ['key' => $key]);
        if (!$existing) {
            $scheduled = $this->notify->schedule($key, 'admin', 'revenue.today', null, $payload);
            return ['scheduled' => $scheduled ? 1 : 0];
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            $this->db->update('cron_events', [
                'payload_json' => $encoded,
            ], 'id = :id', ['id' => (int) $existing['id']]);
        }
        if ((string) $existing['status'] === 'dispatched') {
            $this->refreshNotices($live);
        }

        return ['scheduled' => 0];
    }

    /** @return array{day:string,total:float,count:int,subject:string,body:string} */
    public static function snapshot(Database $db, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? Clock::nowUtc();
        $local = $now->setTimezone(new \DateTimeZone(Clock::displayTimezone()));
        $start = Clock::toUtc($local->setTime(0, 0));
        $end = Clock::toUtc($local->setTime(0, 0)->modify('+1 day'));
        $row = $db->fetch(
            'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS purchases
             FROM payments
             WHERE ' . PaymentService::revenueSql() . '
               AND paid_at >= :a
               AND paid_at < :b',
            [
                'a' => $start->format('Y-m-d H:i:s'),
                'b' => $end->format('Y-m-d H:i:s'),
            ]
        );
        $total = (float) ($row['total'] ?? 0);
        $count = (int) ($row['purchases'] ?? 0);
        $amount = money_format_czk($total);

        return [
            'day' => $local->format('Y-m-d'),
            'total' => $total,
            'count' => $count,
            'subject' => ($count > 0 ? '💰 Dnešní tržba' : '🌱 Dnešní tržba') . ' · ' . $local->format('H:i'),
            'body' => self::bodyText($amount, $count),
        ];
    }

    /** @param array{day:string,subject:string,body:string} $live */
    private function refreshNotices(array $live): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, payload_json FROM notifications WHERE template = 'admin-revenue' AND channel = 'in_app'"
        );
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload) || (string) ($payload['report_day'] ?? '') !== $live['day']) {
                continue;
            }
            $payload['subject'] = $live['subject'];
            $payload['body'] = $live['body'];
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            $this->db->update('notifications', [
                'payload_json' => $encoded,
            ], 'id = :id', ['id' => (int) $row['id']]);
        }
    }

    private static function bodyText(string $amount, int $count): string
    {
        if ($count < 1) {
            return 'Takový je dnešní den: ' . $amount . '. Zatím žádný nákup. 🌱';
        }

        return 'Takový je dnešní den: ' . $amount . ' (' . $count . ' ' . self::purchaseLabel($count) . '). Jedeme dál! 🔥';
    }

    private static function purchaseLabel(int $count): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;
        if ($mod100 < 11 || $mod100 > 14) {
            if ($mod10 === 1) {
                return 'nákup';
            }
            if ($mod10 >= 2 && $mod10 <= 4) {
                return 'nákupy';
            }
        }

        return 'nákupů';
    }
}
