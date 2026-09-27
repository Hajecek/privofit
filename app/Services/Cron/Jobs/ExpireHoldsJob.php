<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Services\ReservationService;

final class ExpireHoldsJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
    ) {
    }

    public function name(): string
    {
        return 'expire-holds';
    }

    public function run(): array
    {
        $ids = ReservationService::make($this->db)->expireHolds();
        $scheduled = 0;
        foreach ($ids as $id) {
            $row = $this->db->fetch(
                "SELECT r.id, r.user_id, r.starts_at, rm.name AS room_name
                 FROM reservations r
                 INNER JOIN rooms rm ON rm.id = r.room_id
                 WHERE r.id = :id AND r.status = 'expired' AND r.user_id IS NOT NULL",
                ['id' => $id]
            );
            if (!$row) {
                continue;
            }
            $when = CronText::when((string) $row['starts_at']);
            $room = (string) $row['room_name'];
            $ok = $this->notify->schedule(
                'user:reservation.hold_expired:' . (int) $row['id'],
                'user',
                'reservation.hold_expired',
                (int) $row['user_id'],
                [
                    'template' => 'reservation-hold-expired',
                    'preference' => 'email_reservations',
                    'push_type' => 'reservation.sync',
                    'subject' => 'Nezaplacená rezervace vypršela',
                    'body' => 'Rezervace ve studiu ' . $room . ' na ' . $when . ' nebyla zaplacena včas a termín se uvolnil.',
                    'action_url' => CronText::link('/user/rezervace'),
                ]
            );
            if ($ok) {
                $scheduled++;
            }
        }
        return ['expired' => count($ids), 'scheduled' => $scheduled];
    }
}
