<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Services\Auth\AuthService;
use App\Services\Cron\Jobs\ReservationReminderJob;
use App\Services\Cron\NotificationDispatcher;
use App\Support\Clock;
use PHPUnit\Framework\TestCase;

final class CronNotificationTest extends TestCase
{
    private Database $db;
    private AuthService $auth;

    protected function setUp(): void
    {
        if (!Env::get('DB_DATABASE')) {
            $this->markTestSkipped('Databáze není nakonfigurována.');
        }
        try {
            $this->db = new Database();
            $this->db->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Databáze není dostupná.');
        }
        $this->db->query("DELETE FROM rate_limit_events WHERE bucket = 'register'");
        $this->auth = AuthService::make($this->db);
    }

    public function testUserReminderAndAdminAlertStaySeparate(): void
    {
        $room = $this->db->fetch('SELECT id FROM rooms WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        if (!$room) {
            $this->markTestSkipped('V databázi není aktivní studio.');
        }
        try {
            $this->db->fetch('SELECT id FROM cron_events LIMIT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Chybí tabulka cron_events. Spusťte migrace.');
        }

        $customer = $this->createVerifiedUser('cronu');
        $admin = $this->createVerifiedUser('crona');
        $customerId = (int) $customer['id'];
        $adminId = (int) $admin['id'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->update('users', ['role' => 'admin'], 'id = :id', ['id' => $adminId]);
            $this->db->update('notification_preferences', [
                'email_reminders' => 0,
            ], 'user_id = :id', ['id' => $customerId]);

            $notify = NotificationDispatcher::make($this->db);
            $silentKey = 'user:reservation.reminder.60:skip:' . $customerId;
            $this->assertTrue($notify->schedule($silentKey, 'user', 'reservation.reminder', $customerId, [
                'template' => 'reservation-reminder',
                'preference' => 'email_reminders',
                'push_type' => 'reservation.sync',
                'subject' => 'Nemá odejít',
                'body' => 'Připomínky jsou vypnuté.',
            ]));
            $this->assertSame('skipped', $notify->dispatchKey($silentKey));
            $this->assertSame(0, $this->countNotes($customerId, 'reservation-reminder'));

            $this->db->update('notification_preferences', [
                'email_reminders' => 1,
            ], 'user_id = :id', ['id' => $customerId]);

            $starts = '2099-06-01 12:40:00';
            $reservationId = (int) $this->db->insert('reservations', [
                'public_id' => Crypto::uuid(),
                'room_id' => (int) $room['id'],
                'user_id' => $customerId,
                'status' => 'confirmed',
                'starts_at' => $starts,
                'ends_at' => '2099-06-01 13:40:00',
                'buffer_minutes' => 15,
                'guest_count' => 1,
                'price' => '0.00',
                'currency' => 'CZK',
                'created_at' => '2099-05-01 08:00:00',
                'updated_at' => '2099-05-01 08:00:00',
            ]);
            $now = new \DateTimeImmutable('2099-06-01 12:00:00', new \DateTimeZone('UTC'));
            $job = new ReservationReminderJob($this->db, $notify, $now);
            $first = $job->run();
            $second = $job->run();
            $this->assertSame(1, $first['users']);
            $this->assertSame(1, $first['admins']);
            $this->assertSame(0, $second['users']);
            $this->assertSame(0, $second['admins']);

            $userKey = 'user:reservation.reminder.60:' . $reservationId;
            $adminKey = 'admin:reservation.starting:' . $reservationId;
            $userEvent = $this->db->fetch('SELECT audience, user_id FROM cron_events WHERE event_key = :key', ['key' => $userKey]);
            $adminEvent = $this->db->fetch('SELECT audience, user_id FROM cron_events WHERE event_key = :key', ['key' => $adminKey]);
            $this->assertSame('user', $userEvent['audience']);
            $this->assertSame($customerId, (int) $userEvent['user_id']);
            $this->assertSame('admin', $adminEvent['audience']);
            $this->assertNull($adminEvent['user_id']);

            $this->assertSame('dispatched', $notify->dispatchKey($userKey));
            $this->assertSame('dispatched', $notify->dispatchKey($adminKey));
            $this->assertSame('dispatched', $notify->dispatchKey($userKey));

            $this->assertSame(1, $this->countChannel($customerId, 'reservation-reminder', 'email'));
            $this->assertSame(1, $this->countChannel($customerId, 'reservation-reminder', 'in_app'));
            $this->assertSame(0, $this->countNotes($customerId, 'admin-reservation'));
            $this->assertSame(1, $this->countChannel($adminId, 'admin-reservation', 'email'));
            $this->assertSame(1, $this->countChannel($adminId, 'admin-reservation', 'in_app'));
            $this->assertSame(0, $this->countNotes($adminId, 'reservation-reminder'));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        unset($customer, $admin);
    }

    public function testAdminHearsAboutMembershipPaymentImmediately(): void
    {
        try {
            $this->db->fetch('SELECT id FROM cron_events LIMIT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Chybí tabulka cron_events. Spusťte migrace.');
        }
        $plan = $this->db->fetch('SELECT id, name FROM membership_plans WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        if (!$plan) {
            $this->markTestSkipped('V databázi není tarif.');
        }

        $customer = $this->createVerifiedUser('tarif');
        $admin = $this->createVerifiedUser('tarifadm');
        $customerId = (int) $customer['id'];
        $adminId = (int) $admin['id'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->update('users', ['role' => 'admin', 'first_name' => 'Admin', 'last_name' => 'Studia'], 'id = :id', ['id' => $adminId]);
            $this->db->update('users', ['first_name' => 'Jana', 'last_name' => 'Nováková'], 'id = :id', ['id' => $customerId]);
            $membershipId = (int) $this->db->insert('memberships', [
                'public_id' => Crypto::uuid(),
                'user_id' => $customerId,
                'plan_id' => (int) $plan['id'],
                'status' => 'pending',
                'created_at' => Clock::utc(),
                'updated_at' => Clock::utc(),
            ]);
            $paymentId = (int) $this->db->insert('payments', [
                'public_id' => Crypto::uuid(),
                'user_id' => $customerId,
                'membership_id' => $membershipId,
                'provider' => 'stripe',
                'amount' => '1100.00',
                'fee_amount' => '23.35',
                'charged_amount' => '1123.35',
                'currency' => 'CZK',
                'status' => 'pending',
                'created_at' => Clock::utc(),
                'updated_at' => Clock::utc(),
            ]);

            $notice = new \App\Services\Billing\MembershipAdminNotice($this->db);
            $notice->send($paymentId, 'paid');
            $notice->send($paymentId, 'paid');
            $this->assertSame(1, $this->countChannel($adminId, 'admin-membership', 'email'));
            $this->assertSame(1, $this->countChannel($adminId, 'admin-membership', 'in_app'));
            $this->assertSame(0, $this->countNotes($customerId, 'admin-membership'));

            $payload = json_decode((string) $this->db->fetchColumn(
                "SELECT payload_json FROM notifications WHERE user_id = :uid AND template = 'admin-membership' AND channel = 'email' ORDER BY id DESC LIMIT 1",
                ['uid' => $adminId]
            ), true);
            $this->assertIsArray($payload);
            $this->assertSame('💳 Tarif zaplacen', $payload['subject']);
            $this->assertStringContainsString('Jana Nováková', (string) $payload['body']);
            $this->assertStringContainsString((string) $plan['name'], (string) $payload['body']);
            $this->assertStringContainsString('1 123,35 Kč', (string) $payload['body']);

            $notice->send($paymentId, 'declined');
            $notice->send($paymentId, 'cancelled');
            $subjects = $this->db->fetchAll(
                "SELECT payload_json FROM notifications WHERE user_id = :uid AND template = 'admin-membership' AND channel = 'in_app' ORDER BY id ASC",
                ['uid' => $adminId]
            );
            $titles = array_map(static function (array $row): string {
                $decoded = json_decode((string) $row['payload_json'], true);
                return (string) ($decoded['subject'] ?? '');
            }, $subjects);
            $this->assertSame(['💳 Tarif zaplacen', '⚠️ Platba odmítnuta', '🚫 Platba zrušena'], $titles);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function countNotes(int $userId, string $template): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND template = :tpl',
            ['uid' => $userId, 'tpl' => $template]
        );
    }

    private function countChannel(int $userId, string $template, string $channel): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND template = :tpl AND channel = :ch',
            ['uid' => $userId, 'tpl' => $template, 'ch' => $channel]
        );
    }

    private function createVerifiedUser(string $prefix): array
    {
        $request = $this->fakeRequest();
        $user = $this->auth->register([
            'username' => $prefix . bin2hex(random_bytes(3)),
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => $prefix . bin2hex(random_bytes(3)) . '@privofit.test',
            'password' => 'spravne-dlouhe-heslo',
            'password_confirmation' => 'spravne-dlouhe-heslo',
            'terms' => '1',
            'privacy' => '1',
        ], $request);
        $this->db->update('users', [
            'status' => 'active',
            'email_verified_at' => Clock::utc(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        return $this->auth->findById((int) $user['id']);
    }

    private function fakeRequest(): \App\Core\Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/registrace';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        return new \App\Core\Request();
    }
}
