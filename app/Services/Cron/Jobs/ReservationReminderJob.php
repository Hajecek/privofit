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
    private const ENDING_FIRST_MINUTES = 15;
    private const ENDING_LAST_MINUTES = 5;

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
        $userCount += $this->remindEnding($now);
        return ['users' => $userCount, 'admins' => $adminCount];
    }

    private function remindEnding(\DateTimeImmutable $now): int
    {
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.user_id, r.ends_at, r.created_at, rm.name AS room_name
             FROM reservations r
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.status = 'confirmed'
               AND r.user_id IS NOT NULL
               AND r.ends_at > :now
               AND r.ends_at <= :until",
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'until' => $now->modify('+' . self::ENDING_FIRST_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
            ]
        );
        $count = 0;
        foreach ($rows as $row) {
            $target = $this->at((string) $row['ends_at']);
            $created = $this->at((string) $row['created_at']);
            $when = Clock::format((string) $row['ends_at'], 'H:i');
            $room = (string) $row['room_name'];
            $id = (int) $row['id'];
            $userId = (int) $row['user_id'];
            if (DueWindow::open($now, $target, $created, self::ENDING_FIRST_MINUTES, self::ENDING_LAST_MINUTES)) {
                if ($this->scheduleEnding($id, $userId, 15, '💦 Poslední série', 'Ve studiu ' . $room . ' máš ještě čtvrthodinku, konec je v ' . $when . '. Ještě jedna pěkná série a pak už jen v klidu dojet. ✨')) {
                    $count++;
                }
            }
            if (DueWindow::open($now, $target, $created, self::ENDING_LAST_MINUTES)) {
                if ($this->scheduleEnding($id, $userId, 5, '⏱️ Už jen pět minut', 'Ve studiu ' . $room . ' zbývá pět minut. Činky si odpočinou, ty taky. Rádi tě tu uvidíme znovu. 💪')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function scheduleEnding(int $reservationId, int $userId, int $leadMinutes, string $subject, string $body): bool
    {
        return $this->notify->schedule(
            'user:reservation.ending.' . $leadMinutes . ':' . $reservationId,
            'user',
            'reservation.ending',
            $userId,
            [
                'template' => 'reservation-ending',
                'skip_email' => true,
                'push_type' => 'reservation.sync',
                'subject' => $subject,
                'body' => $body,
                'action_url' => CronText::link('/user/moje-rezervace'),
            ]
        );
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
