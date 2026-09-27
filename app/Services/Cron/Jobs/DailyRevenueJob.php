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
 * Každých 5 minut odejde nová notifikace s částkou spočítanou z aktuálních plateb daného dne.
 */
final class DailyRevenueJob implements CronJob
{
    public const INTERVAL_MINUTES = 5;

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
        $scheduled = $this->notify->schedule(
            'admin:revenue.today:' . $this->slotKey($local),
            'admin',
            'revenue.today',
            null,
            [
                'template' => 'admin-revenue',
                'skip_email' => true,
                'push_type' => 'admin.sync',
                'report_day' => $live['day'],
                'subject' => $live['subject'],
                'body' => $live['body'],
                'action_url' => CronText::link('/user/sprava'),
            ]
        );

        return ['scheduled' => $scheduled ? 1 : 0];
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

    private function slotKey(\DateTimeImmutable $local): string
    {
        $interval = max(1, self::INTERVAL_MINUTES);
        if ($interval >= 1440) {
            return $local->format('Y-m-d');
        }
        $minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        $bucket = intdiv($minutes, $interval) * $interval;

        return $local->format('Y-m-d') . sprintf('T%02d:%02d', intdiv($bucket, 60), $bucket % 60);
    }
}
