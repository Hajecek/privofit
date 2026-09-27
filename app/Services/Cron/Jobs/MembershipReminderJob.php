<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\DueWindow;
use App\Services\Cron\NotificationDispatcher;
use App\Support\Clock;

final class MembershipReminderJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function name(): string
    {
        return 'membership-reminders';
    }

    public function run(): array
    {
        $now = $this->now ?? Clock::nowUtc();
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.user_id, m.ends_at, m.created_at, p.name AS plan_name, u.first_name, u.last_name
             FROM memberships m
             INNER JOIN membership_plans p ON p.id = m.plan_id
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.status = 'active'
               AND m.ends_at IS NOT NULL
               AND m.ends_at > :now
               AND m.ends_at <= :until",
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'until' => $now->modify('+3 days')->format('Y-m-d H:i:s'),
            ]
        );
        $userCount = 0;
        $adminCount = 0;
        foreach ($rows as $row) {
            $target = new \DateTimeImmutable((string) $row['ends_at'], new \DateTimeZone('UTC'));
            $created = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
            $when = CronText::when((string) $row['ends_at']);
            $plan = (string) $row['plan_name'];
            $id = (int) $row['id'];
            $userId = (int) $row['user_id'];
            if (DueWindow::open($now, $target, $created, 4320, 1440)) {
                if ($this->notify->schedule(
                    'user:membership.expiring.4320:' . $id,
                    'user',
                    'membership.expiring',
                    $userId,
                    [
                        'template' => 'membership-expiring',
                        'preference' => 'email_membership',
                        'push_type' => 'membership.sync',
                        'subject' => 'Členství brzy skončí',
                        'body' => 'Tarif ' . $plan . ' platí ještě 3 dny, do ' . $when . '.',
                        'action_url' => CronText::link('/user/clenstvi'),
                    ]
                )) {
                    $userCount++;
                }
            }
            if (DueWindow::open($now, $target, $created, 1440)) {
                if ($this->notify->schedule(
                    'user:membership.expiring.1440:' . $id,
                    'user',
                    'membership.expiring',
                    $userId,
                    [
                        'template' => 'membership-expiring',
                        'preference' => 'email_membership',
                        'push_type' => 'membership.sync',
                        'subject' => 'Členství končí zítra',
                        'body' => 'Tarif ' . $plan . ' vyprší ' . $when . '.',
                        'action_url' => CronText::link('/user/clenstvi'),
                    ]
                )) {
                    $userCount++;
                }
                if ($this->notify->schedule(
                    'admin:membership.expiring:' . $id,
                    'admin',
                    'membership.expiring',
                    null,
                    [
                        'template' => 'admin-membership',
                        'push_type' => 'admin.sync',
                        'subject' => 'Zákazníkovi končí členství',
                        'body' => CronText::person($row) . ' má tarif ' . $plan . ' do ' . $when . '.',
                        'action_url' => CronText::link('/user/sprava/zakaznici'),
                    ]
                )) {
                    $adminCount++;
                }
            }
        }
        return ['users' => $userCount, 'admins' => $adminCount];
    }
}
