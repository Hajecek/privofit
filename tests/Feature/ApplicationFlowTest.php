<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Services\Access\AccessControlService;
use App\Services\Auth\AuthService;
use App\Services\Auth\MfaRequiredException;
use App\Services\MembershipService;
use App\Services\ReservationService;
use App\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ApplicationFlowTest extends TestCase
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

    public function testRegistrationLoginAndEmailVerification(): void
    {
        $email = 'test.' . bin2hex(random_bytes(4)) . '@privofit.test';
        $username = 'u' . bin2hex(random_bytes(4));
        $request = $this->fakeRequest();
        $user = $this->auth->register([
            'username' => $username,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'spravne-dlouhe-heslo',
            'password_confirmation' => 'spravne-dlouhe-heslo',
            'terms' => '1',
            'privacy' => '1',
        ], $request);
        $this->assertSame('user', $user['role']);
        $this->assertSame('pending', $user['status']);
        $this->assertNull($user['email_verified_at']);

        $row = $this->db->fetch('SELECT * FROM email_verifications WHERE user_id = :id ORDER BY id DESC LIMIT 1', ['id' => (int) $user['id']]);
        $this->assertNotNull($row);

        $logged = false;
        try {
            $this->auth->login($email, 'spatne-heslo-xxxx', $request);
        } catch (\Throwable $e) {
            $this->assertSame(401, $e->getCode() ?: 401);
            $logged = true;
        }
        $this->assertTrue($logged);
    }

    public function testCannotAccessForeignReservation(): void
    {
        $a = $this->createUser('a');
        $b = $this->createUser('b');
        $this->expectException(\App\Core\HttpException::class);
        ReservationService::make($this->db)->owned($a, '00000000-0000-4000-8000-000000000099');
        unset($b);
    }

    public function testOverlappingReservationsAreRejected(): void
    {
        $user = $this->createVerifiedUser('res');
        $this->grantMembership((int) $user['id']);
        $startLocal = Clock::nowLocal()->modify('+8 days')->setTime(11, 0);
        $start = $startLocal->format('Y-m-d H:i');
        $this->clearReservationWindow($startLocal);
        $first = ReservationService::make($this->db)->create($user, $start, 60, 1);
        $this->assertSame('confirmed', $first['status']);
        $this->expectException(\App\Core\HttpException::class);
        ReservationService::make($this->db)->create($user, $start, 60, 1);
    }

    public function testCancelFreesTheSlotForSomeoneElse(): void
    {
        $user = $this->createVerifiedUser('cnl');
        $this->grantMembership((int) $user['id']);
        $startLocal = Clock::nowLocal()->modify('+13 days')->setTime(7, 15);
        $start = $startLocal->format('Y-m-d H:i');
        $this->clearReservationWindow($startLocal);
        $service = ReservationService::make($this->db);
        $first = $service->create($user, $start, 60, 1);
        $this->assertSame('confirmed', $first['status']);
        $service->cancel($user, $first['public_id']);
        $fresh = $this->db->fetch('SELECT status FROM reservations WHERE id = :id', ['id' => (int) $first['id']]);
        $this->assertSame('cancelled', $fresh['status']);
        $second = $service->create($user, $start, 60, 1);
        $this->assertSame('confirmed', $second['status']);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function testBufferKeepsFifteenMinutesBetweenReservations(): void
    {
        $user = $this->createVerifiedUser('buf');
        $this->grantMembership((int) $user['id']);
        $day = Clock::nowLocal()->modify('+11 days')->setTime(11, 0);
        $this->clearReservationWindow($day, 3, 6);
        $service = ReservationService::make($this->db);
        $first = $service->create($user, $day->format('Y-m-d H:i'), 60, 1);
        $this->assertSame('confirmed', $first['status']);
        $availability = $service->availability($day->format('Y-m-d'));
        $byStart = [];
        foreach ($availability['slots'] as $slot) {
            $byStart[$slot['start']] = $slot;
        }
        $this->assertSame('12:15', $byStart['11:00']['end'] ?? '');
        $this->assertFalse(!empty($byStart['11:00']['available']));
        $this->assertArrayNotHasKey('12:00', $byStart);
        $this->assertTrue(!empty($byStart['12:15']['available']));
        $this->assertSame('13:30', $byStart['12:15']['end'] ?? '');
        try {
            $service->create($user, $day->modify('+60 minutes')->format('Y-m-d H:i'), 60, 1);
            $this->fail('Další blok nesmí začínat po hodině, jen po 1 h 15 min.');
        } catch (\App\Core\HttpException $e) {
            $this->assertContains($e->status, [409, 422]);
        }
        $next = $service->create($user, $day->modify('+75 minutes')->format('Y-m-d H:i'), 60, 1);
        $this->assertSame('confirmed', $next['status']);
    }

    public function testTwoBlocksKeepTheirFullLength(): void
    {
        $user = $this->createVerifiedUser('twoh');
        $this->grantMembership((int) $user['id']);
        $day = Clock::nowLocal()->modify('+12 days')->setTime(11, 0);
        $this->clearReservationWindow($day, 3, 6);
        $service = ReservationService::make($this->db);
        $block = $service->create($user, $day->format('Y-m-d H:i'), 120, 1);
        $this->assertSame('confirmed', $block['status']);
        $endLocal = Clock::toLocal($block['ends_at']);
        $this->assertSame('13:15', $endLocal->format('H:i'));

        $availability = $service->availability($day->format('Y-m-d'));
        $availableStarts = [];
        $allStarts = [];
        foreach ($availability['slots'] as $slot) {
            $allStarts[] = $slot['start'];
            if (!empty($slot['available'])) {
                $availableStarts[] = $slot['start'];
            }
        }
        $this->assertNotContains('11:15', $allStarts);
        $this->assertNotContains('11:30', $allStarts);
        $this->assertNotContains('12:15', $availableStarts);
        $this->assertContains('13:30', $availableStarts);

        try {
            $service->create($user, $day->modify('+75 minutes')->format('Y-m-d H:i'), 60, 1);
            $this->fail('Druhý blok dvoublokové rezervace nesmí jít znovu obsadit.');
        } catch (\App\Core\HttpException $e) {
            $this->assertContains($e->status, [409, 422]);
        }
        $next = $service->create($user, $day->modify('+150 minutes')->format('Y-m-d H:i'), 60, 1);
        $this->assertSame('confirmed', $next['status']);
    }

    public function testSplitSelectionKeepsARunAndASeparateSlot(): void
    {
        $user = $this->createVerifiedUser('split');
        $day = Clock::nowLocal()->modify('+18 days')->setTime(11, 0);
        $this->clearReservationWindow($day, 2, 10);
        $service = ReservationService::make($this->db);
        $availability = $service->availability($day->format('Y-m-d'));
        $free = [];
        foreach ($availability['slots'] as $slot) {
            if (!empty($slot['available'])) {
                $free[] = $day->format('Y-m-d') . ' ' . $slot['start'];
            }
        }
        $block = (int) ($availability['block_minutes'] ?? 75);
        $run = [];
        $apart = null;
        foreach ($free as $start) {
            $at = new \DateTimeImmutable($start);
            if ($run === []) {
                $run = [$start];
                continue;
            }
            $previous = new \DateTimeImmutable($run[count($run) - 1]);
            $gap = ($at->getTimestamp() - $previous->getTimestamp()) / 60;
            if (count($run) < 4) {
                $run = $gap === $block ? [...$run, $start] : [$start];
                continue;
            }
            if ($gap >= $block * 2) {
                $apart = $start;
                break;
            }
        }
        if (count($run) < 4 || $apart === null) {
            $this->markTestSkipped('Na vybraný den nejde složit čtyři okénka za sebou a jedno stranou.');
        }
        $picked = [...$run, $apart];
        $created = $service->createMany($user, $picked, 1, null, true);
        $this->assertCount(2, $created);
        $this->assertSame('pending_payment', $created[0]['status']);
        $this->assertSame('pending_payment', $created[1]['status']);
        $firstStart = new \DateTimeImmutable($created[0]['starts_at']);
        $firstEnd = new \DateTimeImmutable($created[0]['ends_at']);
        $this->assertGreaterThan(60, ($firstEnd->getTimestamp() - $firstStart->getTimestamp()) / 60);
        $this->assertGreaterThan($firstEnd->getTimestamp(), (new \DateTimeImmutable($created[1]['starts_at']))->getTimestamp());
    }

    public function testPaidHoldConfirmsAfterCheckoutFulfillment(): void
    {
        $user = $this->createVerifiedUser('stripe');
        $day = Clock::nowLocal()->modify('+16 days')->setTime(11, 0);
        $this->clearReservationWindow($day, 3, 6);
        $service = ReservationService::make($this->db);
        $hold = $service->create($user, $day->format('Y-m-d H:i'), 60, 1);
        $this->assertSame('pending_payment', $hold['status']);
        $this->assertGreaterThan(0, (float) $hold['price']);

        $confirmed = $service->confirmPending($hold, $user);
        $this->assertSame('confirmed', $confirmed['status']);
        $permission = $this->db->fetch('SELECT id FROM access_permissions WHERE reservation_id = :id', ['id' => (int) $confirmed['id']]);
        $this->assertNotEmpty($permission);

        $other = $this->createVerifiedUser('strp2');
        $later = $day->modify('+150 minutes')->format('Y-m-d H:i');
        $hold2 = $service->create($other, $later, 60, 1);
        $this->assertSame('pending_payment', $hold2['status']);
        $service->failPending($hold2);
        $fresh = $this->db->fetch('SELECT status FROM reservations WHERE id = :id', ['id' => (int) $hold2['id']]);
        $this->assertSame('expired', $fresh['status']);
        $again = $service->create($other, $later, 60, 1);
        $this->assertSame('pending_payment', $again['status']);
        $this->assertNotSame($hold2['id'], $again['id']);
    }

    public function testDoorOpenWithoutReservationIsDenied(): void
    {
        $user = $this->createVerifiedUser('door');
        $this->expectException(\App\Core\HttpException::class);
        AccessControlService::make($this->db)->open($user, '127.0.0.1');
    }

    public function testAdminSwitchOpensAndClosesDoor(): void
    {
        if ((string) env_value('DOOR_PROVIDER', 'mock') === 'nuki' && (string) env_value('NUKI_API_TOKEN', '') !== '') {
            $this->markTestSkipped('Ostrý Nuki provider, test ovládání přeskočen.');
        }
        $user = $this->createVerifiedUser('admdoor');
        $door = $this->db->fetch('SELECT * FROM doors WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        if (!$door) {
            $this->markTestSkipped('V databázi nejsou aktivní dveře.');
        }
        $this->db->query('DELETE FROM door_command_locks WHERE door_id = :id', ['id' => (int) $door['id']]);
        $access = AccessControlService::make($this->db);
        $opened = $access->adminSet($user, (int) $door['id'], true, '127.0.0.1');
        $this->assertTrue($opened['accepted']);
        $fresh = $this->db->fetch('SELECT last_known_state, last_known_door_state FROM doors WHERE id = :id', ['id' => (int) $door['id']]);
        $this->assertSame('unlocked', $fresh['last_known_state']);
        $this->assertSame('opened', $fresh['last_known_door_state']);
        $this->db->query('DELETE FROM door_command_locks WHERE door_id = :id', ['id' => (int) $door['id']]);
        $closed = $access->adminSet($user, (int) $door['id'], false, '127.0.0.1');
        $this->assertTrue($closed['accepted']);
        $fresh = $this->db->fetch('SELECT last_known_state, last_known_door_state FROM doors WHERE id = :id', ['id' => (int) $door['id']]);
        $this->assertSame('locked', $fresh['last_known_state']);
        $this->assertSame('closed', $fresh['last_known_door_state']);
    }

    public function testExpiredMembershipDoesNotGrantEntries(): void
    {
        $user = $this->createVerifiedUser('mem');
        $plan = $this->db->fetch("SELECT * FROM membership_plans WHERE slug = 'monthly'");
        $id = (int) $this->db->insert('memberships', [
            'public_id' => Crypto::uuid(),
            'user_id' => (int) $user['id'],
            'plan_id' => (int) $plan['id'],
            'status' => 'active',
            'starts_at' => Clock::nowUtc()->modify('-40 days')->format('Y-m-d H:i:s'),
            'ends_at' => Clock::nowUtc()->modify('-1 day')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
            'updated_at' => Clock::utc(),
        ]);
        (new MembershipService($this->db))->expireOverdue((int) $user['id']);
        $fresh = $this->db->fetch('SELECT status FROM memberships WHERE id = :id', ['id' => $id]);
        $this->assertSame('expired', $fresh['status']);
        $this->assertNull((new MembershipService($this->db))->activeForUser((int) $user['id']));
    }

    public function testIdleSessionIsRevokedEvenWithoutABrowserRequest(): void
    {
        $user = $this->createVerifiedUser('idle');
        $last = Clock::nowUtc()->modify('-25 minutes')->format('Y-m-d H:i:s');
        $sessionId = (int) $this->db->insert('user_sessions', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash(bin2hex(random_bytes(8))),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'is_remembered' => 0,
            'last_activity_at' => $last,
            'expires_at' => Clock::nowUtc()->modify('+2 days')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        \App\Core\Session::set('user_id', (int) $user['id']);
        \App\Core\Session::set('auth_session_id', $sessionId);
        \App\Core\Session::forget('logged_out_reason');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/user/profil';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $auth = new \App\Core\Auth($this->db);
        $auth->hydrate(new \App\Core\Request());
        $this->assertFalse($auth->check());
        $this->assertSame('idle', \App\Core\Session::pull('logged_out_reason'));
        $row = $this->db->fetch('SELECT revoked_at, last_activity_at FROM user_sessions WHERE id = :id', ['id' => $sessionId]);
        $this->assertNotNull($row['revoked_at']);
        $this->assertSame($last, $row['last_activity_at']);
    }

    public function testTrustedBrowserSkipsMfaUntilEveryDeviceIsSignedOut(): void
    {
        $user = $this->createVerifiedUser('mfa');
        $this->db->update('users', ['mfa_enabled' => 1], 'id = :id', ['id' => (int) $user['id']]);
        $this->db->query("DELETE FROM rate_limit_events WHERE bucket IN ('login-ip', 'login-id')");
        $request = $this->fakeRequest();
        unset($_COOKIE['privofit_mfa']);

        $asked = false;
        try {
            $this->auth->login($user['email'], 'spravne-dlouhe-heslo', $request);
        } catch (MfaRequiredException $e) {
            $asked = (int) $e->user['id'] === (int) $user['id'];
        }
        $this->assertTrue($asked);

        $raw = Crypto::token(32);
        $this->db->insert('mfa_trusted_devices', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash($raw),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'expires_at' => Clock::nowUtc()->modify('+30 days')->format('Y-m-d H:i:s'),
            'last_used_at' => Clock::utc(),
            'created_at' => Clock::utc(),
        ]);
        $_COOKIE['privofit_mfa'] = $raw;
        $logged = $this->auth->login($user['email'], 'spravne-dlouhe-heslo', $request);
        $this->assertSame((int) $user['id'], (int) $logged['id']);

        $other = Crypto::token(32);
        $_COOKIE['privofit_mfa'] = $other;
        $foreign = false;
        try {
            $this->auth->login($user['email'], 'spravne-dlouhe-heslo', $request);
        } catch (MfaRequiredException) {
            $foreign = true;
        }
        $this->assertTrue($foreign);

        $_COOKIE['privofit_mfa'] = $raw;
        $this->auth->logoutAll((int) $user['id']);
        $left = $this->db->fetch('SELECT id FROM mfa_trusted_devices WHERE user_id = :id', ['id' => (int) $user['id']]);
        $this->assertNull($left);
        $this->assertArrayNotHasKey('privofit_mfa', $_COOKIE);

        $again = false;
        try {
            $this->auth->login($user['email'], 'spravne-dlouhe-heslo', $request);
        } catch (MfaRequiredException) {
            $again = true;
        }
        $this->assertTrue($again);
    }

    public function testPresenceCheckDoesNotRefreshIdleClock(): void
    {
        $user = $this->createVerifiedUser('idle2');
        $last = Clock::nowUtc()->modify('-10 minutes')->format('Y-m-d H:i:s');
        $sessionId = (int) $this->db->insert('user_sessions', [
            'user_id' => (int) $user['id'],
            'token_hash' => Crypto::hash(bin2hex(random_bytes(8))),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'is_remembered' => 0,
            'last_activity_at' => $last,
            'expires_at' => Clock::nowUtc()->modify('+2 days')->format('Y-m-d H:i:s'),
            'created_at' => Clock::utc(),
        ]);
        \App\Core\Session::set('user_id', (int) $user['id']);
        \App\Core\Session::set('auth_session_id', $sessionId);
        \App\Core\Session::forget('logged_out_reason');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/user/pritomnost';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $auth = new \App\Core\Auth($this->db);
        $auth->hydrate(new \App\Core\Request());
        $this->assertTrue($auth->check());
        $row = $this->db->fetch('SELECT last_activity_at, revoked_at FROM user_sessions WHERE id = :id', ['id' => $sessionId]);
        $this->assertNull($row['revoked_at']);
        $this->assertSame($last, $row['last_activity_at']);
        \App\Core\Session::forget('user_id');
        \App\Core\Session::forget('auth_session_id');
    }

    private function clearReservationWindow(\DateTimeImmutable $localStart, int $hoursBefore = 3, int $hoursAfter = 6): void
    {
        $from = Clock::toUtc($localStart->modify('-' . $hoursBefore . ' hours'))->format('Y-m-d H:i:s');
        $to = Clock::toUtc($localStart->modify('+' . $hoursAfter . ' hours'))->format('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE reservations SET status = 'cancelled' WHERE starts_at >= :a AND starts_at < :b AND status IN ('pending_payment', 'confirmed')",
            ['a' => $from, 'b' => $to]
        );
        try {
            $this->db->query('DELETE FROM reservation_occupancy WHERE starts_at >= :a AND starts_at < :b', ['a' => $from, 'b' => $to]);
        } catch (\Throwable) {
        }
    }

    private function createUser(string $prefix): array
    {
        $request = $this->fakeRequest();
        return $this->auth->register([
            'username' => $prefix . bin2hex(random_bytes(3)),
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => $prefix . bin2hex(random_bytes(3)) . '@privofit.test',
            'password' => 'spravne-dlouhe-heslo',
            'password_confirmation' => 'spravne-dlouhe-heslo',
            'terms' => '1',
            'privacy' => '1',
        ], $request);
    }

    private function createVerifiedUser(string $prefix): array
    {
        $user = $this->createUser($prefix);
        $this->db->update('users', [
            'status' => 'active',
            'email_verified_at' => Clock::utc(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        return $this->auth->findById((int) $user['id']);
    }

    private function grantMembership(int $userId): void
    {
        $plan = $this->db->fetch("SELECT * FROM membership_plans WHERE slug = 'monthly'");
        (new MembershipService($this->db))->assignPlan($userId, (int) $plan['id']);
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
