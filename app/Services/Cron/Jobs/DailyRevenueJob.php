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
 * Souhrn včerejší tržby pro administrátory. Jen v aplikaci, bez e-mailu.
 * Odejde jednou denně v 9:00 místního času a sčítá celý předchozí kalendářní den.
 */
final class DailyRevenueJob implements CronJob
{
    private const SEND_HOUR = 9;

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
        if ((int) $local->format('G') < self::SEND_HOUR) {
            return ['scheduled' => 0];
        }

        $reportDay = $local->setTime(0, 0)->modify('-1 day');
        $live = self::snapshot($this->db, $reportDay);
        $scheduled = $this->notify->schedule(
            'admin:revenue.yesterday:' . $reportDay->format('Y-m-d'),
            'admin',
            'revenue.yesterday',
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
    private static function snapshot(Database $db, \DateTimeImmutable $dayLocal): array
    {
        $day = $dayLocal->setTime(0, 0);
        $start = Clock::toUtc($day);
        $end = Clock::toUtc($day->modify('+1 day'));
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
            'day' => $day->format('Y-m-d'),
            'total' => $total,
            'count' => $count,
            'subject' => ($count > 0 ? '💰 Včerejší tržba' : '🌱 Včerejší tržba') . ' · ' . $day->format('j. n. Y'),
            'body' => self::bodyText($amount, $count),
        ];
    }

    private static function bodyText(string $amount, int $count): string
    {
        if ($count < 1) {
            return 'Takový byl včerejší den: ' . $amount . '. Žádný nákup. 🌱';
        }

        return 'Takový byl včerejší den: ' . $amount . ' (' . $count . ' ' . self::purchaseLabel($count) . '). Jedeme dál! 🔥';
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
