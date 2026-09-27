<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Services\MembershipService;
use App\Support\Clock;

final class ExpireMembershipsJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
    ) {
    }

    public function name(): string
    {
        return 'expire-memberships';
    }

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.user_id, m.ends_at, p.name AS plan_name, u.first_name, u.last_name
             FROM memberships m
             INNER JOIN membership_plans p ON p.id = m.plan_id
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.status = 'active' AND m.ends_at IS NOT NULL AND m.ends_at < :now",
            ['now' => Clock::utc()]
        );
        $memberships = new MembershipService($this->db);
        $expiredUsers = $memberships->expireOverdue();
        $scheduled = 0;
        $seen = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if (isset($seen[$userId]) || !in_array($userId, $expiredUsers, true)) {
                continue;
            }
            $seen[$userId] = true;
            if ($memberships->activeForUser($userId)) {
                continue;
            }
            $when = CronText::when((string) $row['ends_at']);
            $plan = (string) $row['plan_name'];
            $person = CronText::person($row);
            $membershipId = (int) $row['id'];
            $userOk = $this->notify->schedule(
                'user:membership.expired:' . $membershipId,
                'user',
                'membership.expired',
                $userId,
                [
                    'template' => 'membership-expired',
                    'push_type' => 'membership.sync',
                    'subject' => 'Členství skončilo',
                    'body' => 'Platnost tarifu ' . $plan . ' skončila ' . $when . '. Nové rezervace ze členství teď nejdou.',
                    'action_url' => CronText::link('/user/clenstvi'),
                ]
            );
            $adminOk = $this->notify->schedule(
                'admin:membership.expired:' . $membershipId,
                'admin',
                'membership.expired',
                null,
                [
                    'template' => 'admin-membership',
                    'push_type' => 'admin.sync',
                    'subject' => 'Zákazníkovi skončilo členství',
                    'body' => $person . ' už nemá aktivní tarif ' . $plan . '. Platnost skončila ' . $when . '.',
                    'action_url' => CronText::link('/user/sprava/zakaznici'),
                ]
            );
            if ($userOk || $adminOk) {
                $scheduled++;
            }
        }
        return ['expired' => count($expiredUsers), 'scheduled' => $scheduled];
    }
}
