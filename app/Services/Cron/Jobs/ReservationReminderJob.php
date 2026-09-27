<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\Cron\CronText;
use App\Services\Cron\DueWindow;
use App\Services\Cron\NotificationDispatcher;
use App\Support\Clock;

final class ReservationReminderJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationDispatcher $notify,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function name(): string
    {
        return 'reservation-reminders';
    }

    public function run(): array
    {
        $now = $this->now ?? Clock::nowUtc();
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.user_id, r.starts_at, r.created_at, r.guest_count, rm.name AS room_name,
                    u.first_name, u.last_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.status = 'confirmed'
               AND r.starts_at > :now
               AND r.starts_at <= :until",
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'until' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
            ]
        );
        $userCount = 0;
        $adminCount = 0;
        foreach ($rows as $row) {
            $target = $this->at((string) $row['starts_at']);
            $created = $this->at((string) $row['created_at']);
            $when = CronText::when((string) $row['starts_at']);
            $room = (string) $row['room_name'];
            $id = (int) $row['id'];
            $userId = (int) $row['user_id'];
            if (DueWindow::open($now, $target, $created, 1440, 60)) {
                if ($this->notify->schedule(
                    'user:reservation.reminder.1440:' . $id,
                    'user',
                    'reservation.reminder',
                    $userId,
                    [
                        'template' => 'reservation-reminder',
                        'preference' => 'email_reminders',
                        'push_type' => 'reservation.sync',
                        'subject' => 'Připomínka rezervace',
                        'body' => 'Zítra vás čeká trénink ve studiu ' . $room . ', ' . $when . '.',
                        'action_url' => CronText::link('/user/moje-rezervace'),
                    ]
                )) {
                    $userCount++;
                }
            }
            if (DueWindow::open($now, $target, $created, 60)) {
                if ($this->notify->schedule(
                    'user:reservation.reminder.60:' . $id,
                    'user',
                    'reservation.reminder',
                    $userId,
                    [
                        'template' => 'reservation-reminder',
                        'preference' => 'email_reminders',
                        'push_type' => 'reservation.sync',
                        'subject' => 'Rezervace začíná za hodinu',
                        'body' => 'Za hodinu začíná váš termín ve studiu ' . $room . ', ' . $when . '.',
                        'action_url' => CronText::link('/user/moje-rezervace'),
                    ]
                )) {
                    $userCount++;
                }
                $guests = (int) ($row['guest_count'] ?? 1);
                $guestLabel = '';
                if ($guests > 1) {
                    $guestLabel = ' (' . $guests . ' ' . ($guests < 5 ? 'osoby' : 'osob') . ')';
                }
                $who = CronText::person($row) . $guestLabel;
                if ($this->notify->schedule(
                    'admin:reservation.starting:' . $id,
                    'admin',
                    'reservation.starting',
                    null,
                    [
                        'template' => 'admin-reservation',
                        'push_type' => 'admin.sync',
                        'subject' => 'Brzy začíná rezervace',
                        'body' => $who . ' má za hodinu termín ve studiu ' . $room . ', ' . $when . '.',
                        'action_url' => CronText::link('/user/sprava/rezervace'),
                    ]
                )) {
                    $adminCount++;
                }
            }
        }
        return ['users' => $userCount, 'admins' => $adminCount];
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
