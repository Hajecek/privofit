<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Access\AccessControlService;
use App\Services\AppPushService;
use App\Services\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Billing\PaymentService;
use App\Services\Content\ContentService;
use App\Services\MembershipService;
use App\Services\ReservationService;
use App\Support\Clock;

final class AdminController extends Controller
{
    public function dashboard(): never
    {
        if (is_admin_user() && admin_view_mode() === 'user') {
            $this->redirect('/user');
        }
        $db = $this->app->db();
        $todayStart = Clock::toUtc(Clock::nowLocal()->setTime(0, 0))->format('Y-m-d H:i:s');
        $todayEnd = Clock::toUtc(Clock::nowLocal()->setTime(0, 0)->modify('+1 day'))->format('Y-m-d H:i:s');
        $now = Clock::utc();

        $todayReservations = $db->fetchAll(
            "SELECT r.id, r.public_id, r.starts_at, r.ends_at, r.buffer_minutes, r.status, r.guest_count,
                    u.first_name, u.last_name, u.username, u.public_id AS user_public_id,
                    rm.name AS room_name
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN rooms rm ON rm.id = r.room_id
             WHERE r.starts_at >= :a AND r.starts_at < :b
               AND r.status IN ('confirmed', 'pending_payment')
             ORDER BY r.starts_at ASC
             LIMIT 20",
            ['a' => $todayStart, 'b' => $todayEnd]
        );

        $recentDenied = $db->fetchAll(
            "SELECT l.created_at, l.denial_reason, l.authorization_result, u.username, u.first_name, u.last_name, u.public_id
             FROM access_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.authorization_result = 'denied' AND l.created_at >= :a
             ORDER BY l.created_at DESC
             LIMIT 8",
            ['a' => $todayStart]
        );

        $next = $db->fetch(
            "SELECT r.starts_at, r.ends_at, u.first_name, u.last_name, rm.name AS room_name
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN rooms rm ON rm.id = r.room_id
             WHERE r.status = 'confirmed' AND r.starts_at > :now
             ORDER BY r.starts_at ASC
             LIMIT 1",
            ['now' => $now]
        );

        $stats = [
            'active_members' => (int) $db->fetchColumn("SELECT COUNT(*) FROM memberships WHERE status = 'active'"),
            'today_reservations' => count($todayReservations),
            'current' => ReservationService::make($db)->occupancyNow(),
            'next' => $next,
            'today_list' => $todayReservations,
            'denied_list' => $recentDenied,
            'revenue' => (string) $db->fetchColumn(
                'SELECT COALESCE(SUM(amount),0) FROM payments WHERE ' . PaymentService::revenueSql() . ' AND paid_at >= :a AND paid_at < :b',
                [
                    'a' => Clock::toUtc(Clock::nowLocal()->modify('-30 days')->setTime(0, 0))->format('Y-m-d H:i:s'),
                    'b' => Clock::toUtc(Clock::nowLocal()->setTime(0, 0)->modify('+1 day'))->format('Y-m-d H:i:s'),
                ]
            ),
            'revenue_today' => (string) $db->fetchColumn(
                'SELECT COALESCE(SUM(amount),0) FROM payments WHERE ' . PaymentService::revenueSql() . ' AND paid_at >= :a AND paid_at < :b',
                ['a' => $todayStart, 'b' => $todayEnd]
            ),
            'revenue_yesterday' => (string) $db->fetchColumn(
                'SELECT COALESCE(SUM(amount),0) FROM payments WHERE ' . PaymentService::revenueSql() . ' AND paid_at >= :a AND paid_at < :b',
                [
                    'a' => Clock::toUtc(Clock::nowLocal()->modify('-1 day')->setTime(0, 0))->format('Y-m-d H:i:s'),
                    'b' => $todayStart,
                ]
            ),
            'revenue_count_30' => (int) $db->fetchColumn(
                'SELECT COUNT(*) FROM payments WHERE ' . PaymentService::revenueSql() . ' AND paid_at >= :a AND paid_at < :b',
                [
                    'a' => Clock::toUtc(Clock::nowLocal()->modify('-30 days')->setTime(0, 0))->format('Y-m-d H:i:s'),
                    'b' => Clock::toUtc(Clock::nowLocal()->setTime(0, 0)->modify('+1 day'))->format('Y-m-d H:i:s'),
                ]
            ),
            'entries' => (int) $db->fetchColumn("SELECT COUNT(*) FROM access_logs WHERE authorization_result = 'granted' AND created_at >= :a", ['a' => $todayStart]),
            'failed_access' => (int) $db->fetchColumn("SELECT COUNT(*) FROM access_logs WHERE authorization_result = 'denied' AND created_at >= :a", ['a' => $todayStart]),
            'door' => AccessControlService::make($db)->doorStatus(),
            'interest' => 0,
            'customers' => (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role = \'user\''),
        ];
        try {
            $stats['interest'] = (int) $db->fetchColumn('SELECT COUNT(*) FROM interest_signups');
        } catch (\PDOException) {
        }

        $chartFrom = Clock::nowLocal()->modify('-29 days')->setTime(0, 0);
        $chartTo = Clock::nowLocal()->setTime(0, 0)->modify('+1 day');
        $chart = $this->revenueAnalytics($chartFrom, $chartTo);

        $this->view('admin/dashboard', [
            'title' => 'Přehled správy',
            'stats' => $stats,
            'chart' => $chart,
            'pageScripts' => ['js/rev-charts.js', 'js/admin-dash.js', 'js/customers.js'],
        ]);
    }

    public function users(Request $request): never
    {
        $q = trim((string) $request->query('q', ''));
        $sql = "SELECT u.*,
                       m.status AS membership_status,
                       p.name AS membership_name,
                       p.type AS membership_type
                FROM users u
                LEFT JOIN memberships m ON m.id = (
                    SELECT m2.id FROM memberships m2
                    WHERE m2.user_id = u.id AND m2.status = 'active'
                      AND (m2.ends_at IS NULL OR m2.ends_at > UTC_TIMESTAMP())
                    ORDER BY m2.ends_at IS NULL DESC, m2.ends_at DESC
                    LIMIT 1
                )
                LEFT JOIN membership_plans p ON p.id = m.plan_id
                WHERE u.deleted_at IS NULL";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (u.email LIKE :q OR u.username LIKE :q2 OR u.first_name LIKE :q3 OR u.last_name LIKE :q4)';
            $like = '%' . $q . '%';
            $params = ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        $sql .= ' ORDER BY u.created_at DESC LIMIT 200';
        $this->view('admin/users', [
            'title' => 'Zákazníci',
            'users' => $this->app->db()->fetchAll($sql, $params),
            'q' => $q,
            'pageScripts' => ['js/customers.js'],
        ]);
    }

    public function userShow(Request $request, array $params): never
    {
        $user = $this->app->db()->fetch('SELECT * FROM users WHERE public_id = :id AND deleted_at IS NULL', ['id' => $params['id']]);
        if (!$user) {
            throw new HttpException(404, 'Uživatel nebyl nalezen.');
        }
        $memberships = new MembershipService($this->app->db());
        $this->view('admin/user-show', [
            'title' => $user['first_name'] . ' ' . $user['last_name'],
            'customer' => $user,
            'activeMembership' => $memberships->activeForUser((int) $user['id']),
            'memberships' => $memberships->history((int) $user['id']),
            'reservations' => ReservationService::make($this->app->db())->forUser((int) $user['id']),
            'payments' => $this->app->db()->fetchAll('SELECT * FROM payments WHERE user_id = :id ORDER BY created_at DESC LIMIT 30', ['id' => (int) $user['id']]),
            'access' => $this->app->db()->fetchAll('SELECT * FROM access_logs WHERE user_id = :id ORDER BY created_at DESC LIMIT 50', ['id' => (int) $user['id']]),
            'plans' => array_values(array_filter(
                $memberships->plans(true),
                static fn (array $plan): bool => ($plan['type'] ?? '') !== 'credit'
            )),
            'pageScripts' => ['js/customers.js'],
        ]);
    }

    public function reservations(Request $request): never
    {
        if (is_admin_user() && admin_view_mode() === 'user') {
            $this->redirect('/user');
        }
        $filter = (string) $request->query('stav', 'nadchazejici');
        $allowed = ['nadchazejici', 'dnes', 'zrusene', 'vse'];
        if (!in_array($filter, $allowed, true)) {
            $filter = 'nadchazejici';
        }
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }
        $db = $this->app->db();
        $now = Clock::utc();
        $todayStart = Clock::toUtc(Clock::nowLocal()->setTime(0, 0))->format('Y-m-d H:i:s');
        $todayEnd = Clock::toUtc(Clock::nowLocal()->setTime(0, 0)->modify('+1 day'))->format('Y-m-d H:i:s');

        $sql = "SELECT r.public_id, r.starts_at, r.ends_at, r.buffer_minutes, r.status, r.guest_count, r.price,
                       r.cancellation_reason, r.membership_id,
                       u.first_name, u.last_name, u.username, u.email, u.public_id AS user_public_id,
                       rm.name AS room_name,
                       p.id AS payment_id, p.provider AS payment_provider, p.status AS payment_status,
                       p.amount AS payment_amount, p.fee_amount, p.charged_amount, p.stripe_details,
                       p.provider_reference
                FROM reservations r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN rooms rm ON rm.id = r.room_id
                LEFT JOIN payments p ON p.id = (
                    SELECT p2.id FROM payments p2
                    WHERE p2.reservation_id = r.id
                       OR (p2.metadata_json IS NOT NULL AND p2.metadata_json LIKE CONCAT('%', r.public_id, '%'))
                    ORDER BY p2.id DESC
                    LIMIT 1
                )
                WHERE r.status <> 'expired'";
        $params = [];
        if ($filter === 'nadchazejici') {
            $sql .= " AND r.status IN ('confirmed', 'pending_payment') AND r.ends_at >= :now";
            $params['now'] = $now;
        } elseif ($filter === 'dnes') {
            $sql .= ' AND r.starts_at >= :a AND r.starts_at < :b';
            $params['a'] = $todayStart;
            $params['b'] = $todayEnd;
        } elseif ($filter === 'zrusene') {
            $sql .= " AND r.status = 'cancelled'";
        }
        if ($q !== '') {
            $sql .= ' AND (u.email LIKE :q OR u.username LIKE :q2 OR u.first_name LIKE :q3 OR u.last_name LIKE :q4)';
            $like = '%' . $q . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }
        $sql .= match ($filter) {
            'nadchazejici', 'dnes' => ' ORDER BY r.starts_at ASC',
            'zrusene' => ' ORDER BY r.cancelled_at DESC, r.starts_at DESC',
            default => ' ORDER BY r.starts_at DESC',
        };
        $sql .= ' LIMIT 200';
        $rows = $db->fetchAll($sql, $params);
        $stubs = [];
        foreach ($rows as $row) {
            if (($row['payment_provider'] ?? '') !== 'stripe' || empty($row['payment_id'])) {
                continue;
            }
            $stubs[] = [
                'id' => (int) $row['payment_id'],
                'provider' => 'stripe',
                'provider_reference' => (string) ($row['provider_reference'] ?? ''),
                'stripe_details' => $row['stripe_details'] ?? null,
            ];
        }
        if ($stubs !== []) {
            $filled = [];
            foreach ((new PaymentService($db))->withStripeFacts($stubs) as $payment) {
                $filled[(int) $payment['id']] = $payment['stripe_details'] ?? null;
            }
            foreach ($rows as $index => $row) {
                $paymentId = (int) ($row['payment_id'] ?? 0);
                if ($paymentId && !empty($filled[$paymentId])) {
                    $rows[$index]['stripe_details'] = $filled[$paymentId];
                }
            }
        }

        $counts = $db->fetch(
            "SELECT
                SUM(r.status IN ('confirmed', 'pending_payment') AND r.ends_at >= :now) AS nadchazejici,
                SUM(r.starts_at >= :a AND r.starts_at < :b AND r.status <> 'expired') AS dnes,
                SUM(r.status = 'cancelled') AS zrusene,
                SUM(r.status <> 'expired') AS vse
             FROM reservations r",
            ['now' => $now, 'a' => $todayStart, 'b' => $todayEnd]
        ) ?: [];

        $this->view('admin/reservations', [
            'title' => 'Rezervace',
            'rows' => $rows,
            'filter' => $filter,
            'q' => $q,
            'counts' => $counts,
            'pageScripts' => ['js/customers.js', 'js/payment-detail.js'],
        ]);
    }

    public function cancelReservation(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $reason = trim((string) $request->input('cancellation_reason', ''));
        if (mb_strlen($reason) > 255) {
            $this->flashError('Komentář může mít nejvýš 255 znaků.');
            $this->redirectAfterReservationCancel($request);
        }
        try {
            $outcome = ReservationService::make($this->app->db())->cancel(
                $actor,
                (string) $params['id'],
                true,
                $reason !== '' ? $reason : null
            );
            (new AuditService($this->app->db()))->log(
                (int) $actor['id'],
                'reservation.cancel',
                'reservation',
                (string) $params['id'],
                null,
                $reason !== '' ? $reason : 'cancelled',
                $request->ip()
            );
            $this->flashSuccess(match ($outcome) {
                'refunded' => 'Rezervace byla zrušena. Peníze se vrací zákazníkovi.',
                'late' => 'Rezervace byla zrušena. Na vrácení peněz už není nárok.',
                'entry' => 'Rezervace byla zrušena. Vstup se vrátil do členství.',
                'entries' => 'Rezervace byla zrušena. Vstupy se vrátily do členství.',
                default => 'Rezervace byla zrušena.',
            });
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirectAfterReservationCancel($request);
    }

    private function redirectAfterReservationCancel(Request $request): never
    {
        $back = (string) $request->input('redirect', 'list');
        if ($back === 'dashboard') {
            $this->redirect('/user/sprava');
        }
        if ($back === 'customer') {
            $customer = (string) $request->input('customer', '');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $customer) === 1) {
                $this->redirect('/user/sprava/zakaznici/' . $customer);
            }
        }
        $stav = (string) $request->input('stav', 'nadchazejici');
        if (!in_array($stav, ['nadchazejici', 'dnes', 'zrusene', 'vse'], true)) {
            $stav = 'nadchazejici';
        }
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }
        $query = [];
        if ($stav !== 'nadchazejici') {
            $query['stav'] = $stav;
        }
        if ($q !== '') {
            $query['q'] = $q;
        }
        $this->redirect('/user/sprava/rezervace' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    public function userUpdate(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        $status = (string) $request->input('status', $user['status']);
        if (!in_array($status, ['pending', 'active', 'blocked'], true)) {
            throw new HttpException(422, 'Neplatný stav.');
        }
        $this->setCustomerStatus($actor, $user, $status, (string) $request->input('blocked_reason', ''), $request->ip());
        $this->flashSuccess('Stav účtu byl uložen.');
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function userBlock(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        if ((int) $user['id'] === (int) $actor['id']) {
            throw new HttpException(422, 'Nemůžeš zablokovat vlastní účet.');
        }
        $reason = trim((string) $request->input('blocked_reason', ''));
        $this->setCustomerStatus($actor, $user, 'blocked', $reason, $request->ip());
        $this->flashSuccess('Účet byl zablokován.');
        $this->redirectCustomerAction($request, $user);
    }

    public function userUnblock(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        $this->setCustomerStatus($actor, $user, 'active', '', $request->ip());
        $this->flashSuccess('Účet byl odblokován.');
        $this->redirectCustomerAction($request, $user);
    }

    public function userDelete(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        if ((int) $user['id'] === (int) $actor['id']) {
            throw new HttpException(422, 'Nemůžeš smazat vlastní účet.');
        }
        if (($user['role'] ?? '') === 'admin') {
            throw new HttpException(422, 'Administrátorský účet nelze smazat.');
        }
        $reason = trim((string) $request->input('delete_reason', ''));
        if ($reason === '') {
            $reason = 'Tvůj účet PRIVOFIT byl smazán administrátorem.';
        }
        AppPushService::make($this->app->db())->accountDeleted($user, $reason);
        (new AuditService($this->app->db()))->log((int) $actor['id'], 'user.delete', 'user', $user['id'], $user['status'], 'deleted', $request->ip());
        AuthService::make($this->app->db())->deleteAccount($user, $reason);
        $this->flashSuccess('Účet byl smazán.');
        $this->redirect('/user/sprava/zakaznici');
    }

    private function findCustomer(string $publicId): array
    {
        $user = $this->app->db()->fetch('SELECT * FROM users WHERE public_id = :id AND deleted_at IS NULL', ['id' => $publicId]);
        if (!$user) {
            throw new HttpException(404, 'Uživatel nebyl nalezen.');
        }
        return $user;
    }

    private function setCustomerStatus(array $actor, array $user, string $status, string $reason, string $ip): void
    {
        if ($status === 'blocked') {
            $reason = trim($reason) !== '' ? trim($reason) : 'Tvůj účet byl zablokován administrátorem.';
        }
        $this->app->db()->update('users', [
            'status' => $status,
            'blocked_at' => $status === 'blocked' ? Clock::utc() : null,
            'blocked_reason' => $status === 'blocked' ? $reason : null,
            'updated_at' => Clock::utc(),
        ], 'id = :id', ['id' => (int) $user['id']]);
        (new AuditService($this->app->db()))->log((int) $actor['id'], 'user.status', 'user', $user['id'], $user['status'], $status, $ip);
        if ($status === 'blocked') {
            AuthService::make($this->app->db())->logoutAll((int) $user['id']);
            AppPushService::make($this->app->db())->accountStatusChanged($user, $status, $reason);
        } elseif ($status !== (string) $user['status']) {
            AppPushService::make($this->app->db())->accountStatusChanged($user, $status);
        }
    }

    private function redirectCustomerAction(Request $request, array $user): never
    {
        $back = (string) $request->input('redirect', '');
        if ($back === 'list') {
            $this->redirect('/user/sprava/zakaznici');
        }
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function userRole(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        if ($actor['role'] !== 'admin') {
            throw new HttpException(403, 'Role smí měnit pouze administrátor.');
        }
        $user = $this->findCustomer($params['id']);
        $role = (string) $request->input('role');
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new HttpException(422, 'Neplatná role.');
        }
        if ($user['role'] === 'admin' && $role !== 'admin') {
            $admins = (int) $this->app->db()->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL");
            if ($admins <= 1) {
                throw new HttpException(422, 'Nelze odebrat roli poslednímu aktivnímu administrátorovi.');
            }
        }
        $this->app->db()->update('users', ['role' => $role], 'id = :id', ['id' => (int) $user['id']]);
        (new AuditService($this->app->db()))->log((int) $actor['id'], 'user.role', 'user', $user['id'], $user['role'], $role, $request->ip());
        $this->flashSuccess('Role byla změněna.');
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function assignMembership(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        $planId = (int) $request->input('plan_id');
        if ($planId <= 0) {
            throw new HttpException(422, 'Vyber tarif.');
        }
        (new MembershipService($this->app->db()))->assignPlan((int) $user['id'], $planId, 'active', (int) $actor['id']);
        AppPushService::make($this->app->db())->membershipAssigned((int) $user['id']);
        $this->flashSuccess('Členství bylo přiřazeno.');
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function revokeMembership(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        $membershipId = trim((string) $request->input('membership_id', ''));
        $service = new MembershipService($this->app->db());
        if ($membershipId !== '') {
            $service->revokeMembership((int) $user['id'], $membershipId, (int) $actor['id']);
            $this->flashSuccess('Členství bylo odebráno.');
        } else {
            $count = $service->revokeActive((int) $user['id'], (int) $actor['id']);
            $this->flashSuccess($count > 0 ? 'Aktivní členství bylo odebráno.' : 'Žádné aktivní členství k odebrání.');
        }
        (new AuditService($this->app->db()))->log((int) $actor['id'], 'membership.revoke', 'user', $user['id'], null, $membershipId !== '' ? $membershipId : 'active', $request->ip());
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function resetMembershipHistory(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $user = $this->findCustomer($params['id']);
        $count = (new MembershipService($this->app->db()))->clearHistory((int) $user['id']);
        (new AuditService($this->app->db()))->log((int) $actor['id'], 'membership.reset', 'user', $user['id'], (string) $count, '0', $request->ip());
        $this->flashSuccess($count > 0 ? 'Historie členství byla vymazána.' : 'Historie členství už byla prázdná.');
        $this->redirect('/user/sprava/zakaznici/' . $user['public_id']);
    }

    public function content(): never
    {
        $content = new ContentService($this->app->db());
        $this->view('admin/content', [
            'title' => 'Obsah webu',
            'faqs' => $content->allFaqs(),
            'contact' => $content->contact(),
        ]);
    }

    public function saveContent(Request $request): never
    {
        $this->app->settings()->set('contact.address', (string) $request->input('address'));
        $this->app->settings()->set('contact.email', (string) $request->input('email'));
        $this->app->settings()->set('contact.phone', (string) $request->input('phone'));
        $this->app->settings()->set('contact.hours', (string) $request->input('hours'));
        $this->app->settings()->set('contact.map_embed', (string) $request->input('map_embed'));
        $this->flashSuccess('Kontaktní údaje byly uloženy.');
        bump_live();
        $this->redirect('/user/sprava/obsah');
    }

    public function saveFaq(Request $request): never
    {
        $this->app->db()->insert('faq_items', [
            'question' => (string) $request->input('question'),
            'answer' => (string) $request->input('answer'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_published' => 1,
        ]);
        $this->flashSuccess('FAQ položka byla přidána.');
        bump_live();
        $this->redirect('/user/sprava/obsah');
    }

    public function plans(): never
    {
        $this->view('admin/plans', [
            'title' => 'Členství a ceník',
            'plans' => (new MembershipService($this->app->db()))->plans(false),
        ]);
    }

    public function savePlan(Request $request): never
    {
        $id = (int) $request->input('id', 0);
        $data = [
            'name' => (string) $request->input('name'),
            'description' => (string) $request->input('description'),
            'type' => (string) $request->input('type', 'single'),
            'price' => (string) $request->input('price', '0'),
            'entries' => $request->input('entries') !== '' ? (int) $request->input('entries') : null,
            'duration_days' => $request->input('duration_days') !== '' ? (int) $request->input('duration_days') : null,
            'max_guests' => (int) $request->input('max_guests', 0),
            'is_active' => $request->input('is_active') ? 1 : 0,
            'sort_order' => (int) $request->input('sort_order', 0),
        ];
        if ($id) {
            $this->app->db()->update('membership_plans', $data, 'id = :id', ['id' => $id]);
        } else {
            $this->app->db()->insert('membership_plans', $data + [
                'public_id' => \App\Core\Crypto::uuid(),
                'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $request->input('name')) ?? 'plan'),
                'currency' => 'CZK',
            ]);
        }
        $this->flashSuccess('Tarif byl uložen.');
        bump_live();
        $this->redirect('/user/sprava/tarify');
    }

    public function access(): never
    {
        $this->view('admin/access', [
            'title' => 'Vstupní systém',
            'status' => AccessControlService::make($this->app->db())->doorStatus(),
            'doors' => $this->app->db()->fetchAll('SELECT * FROM doors'),
            'logs' => $this->app->db()->fetchAll('SELECT l.*, u.username FROM access_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 80'),
        ]);
    }

    public function testOpen(Request $request): never
    {
        $actor = $this->requireUser();
        $password = (string) $request->input('password', '');
        if (!AuthService::make($this->app->db()) || !\App\Core\Crypto::verifyPassword($password, (string) $actor['password_hash'])) {
            $this->flashError('Opětovné ověření selhalo.');
            $this->redirect('/user/sprava/vstup');
        }
        $this->flashSuccess('Testovací režim je aktivní. Ostré otevření se spustí až po konfiguraci Nuki.');
        $this->redirect('/user/sprava/vstup');
    }

    public function settings(): never
    {
        if (!$this->app->auth()->hasRole('admin')) {
            throw new HttpException(403);
        }
        $this->view('admin/settings', [
            'title' => 'Nastavení systému',
            'audit' => $this->app->db()->fetchAll('SELECT a.*, u.username FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 100'),
        ]);
    }

    public function saveSettings(Request $request): never
    {
        if (!$this->app->auth()->hasRole('admin')) {
            throw new HttpException(403);
        }
        $keys = [
            'reservation.slot_minutes' => 15,
            'reservation.min_minutes' => 60,
            'reservation.max_minutes' => 180,
            'reservation.buffer_minutes' => 15,
            'reservation.cancellation_hours' => 12,
            'access.early_minutes' => 5,
            'access.late_minutes' => 5,
        ];
        foreach ($keys as $key => $default) {
            $raw = $request->input($key, $request->input(str_replace('.', '_', $key), $default));
            $value = max(0, (int) $raw);
            $this->app->settings()->set($key, (string) $value);
        }
        (new AuditService($this->app->db()))->log($this->app->auth()->id(), 'settings.update', 'app_settings', null, null, $request->all(), $request->ip());
        $this->flashSuccess('Nastavení bylo uloženo.');
        bump_live();
        $this->redirect('/user/sprava/nastaveni');
    }

    public function interest(Request $request): never
    {
        $q = trim((string) $request->query('q', ''));
        $sql = 'SELECT * FROM interest_signups';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE email LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $this->view('admin/interest', [
            'title' => 'Předobjednávky',
            'signups' => $this->app->db()->fetchAll($sql, $params),
            'q' => $q,
            'total' => (int) $this->app->db()->fetchColumn('SELECT COUNT(*) FROM interest_signups'),
            'pageScripts' => ['js/customers.js'],
        ]);
    }

    public function deleteInterest(Request $request, array $params): never
    {
        $actor = $this->requireUser();
        $id = (int) ($params['id'] ?? 0);
        $row = $this->app->db()->fetch('SELECT * FROM interest_signups WHERE id = :id', ['id' => $id]);
        if (!$row) {
            throw new HttpException(404, 'Záznam zájmu nebyl nalezen.');
        }
        $this->app->db()->query('DELETE FROM interest_signups WHERE id = :id', ['id' => $id]);
        (new AuditService($this->app->db()))->log(
            (int) $actor['id'],
            'interest.delete',
            'interest_signup',
            $id,
            $row['email'],
            null,
            $request->ip()
        );
        $this->flashSuccess('E-mail byl ze zájmu odstraněn.');
        $this->redirect('/user/sprava/zajem');
    }

    public function revenue(Request $request): never
    {
        $period = $this->resolveRevenuePeriod($request);
        $db = $this->app->db();

        $payments = $db->fetchAll(
            "SELECT p.*,
                    u.first_name, u.last_name, u.username, u.public_id AS user_public_id,
                    r.public_id AS reservation_public_id,
                    m.id AS membership_row_id
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN reservations r ON r.id = p.reservation_id
             LEFT JOIN memberships m ON m.id = p.membership_id
             WHERE " . PaymentService::revenueSql('p') . "
               AND p.paid_at >= :from
               AND p.paid_at < :to
             ORDER BY p.paid_at DESC
             LIMIT 500",
            ['from' => $period['from'], 'to' => $period['to']]
        );
        $payments = (new PaymentService($db))->withStripeFacts($payments);

        $total = 0.0;
        $reservationTotal = 0.0;
        $membershipTotal = 0.0;
        $otherTotal = 0.0;
        foreach ($payments as $payment) {
            $amount = (float) ($payment['amount'] ?? 0);
            $total += $amount;
            if (!empty($payment['reservation_id'])) {
                $reservationTotal += $amount;
            } elseif (!empty($payment['membership_id'])) {
                $membershipTotal += $amount;
            } else {
                $otherTotal += $amount;
            }
        }

        $count = count($payments);
        $average = $count > 0 ? $total / $count : 0.0;

        $analytics = $this->revenueAnalytics(
            Clock::parseLocal($period['start_day'] . ' 00:00:00'),
            Clock::parseLocal($period['end_day'] . ' 00:00:00')->modify('+1 day')
        );

        $this->view('admin/revenue', [
            'title' => 'Tržby',
            'period' => $period,
            'payments' => $payments,
            'summary' => [
                'total' => $total,
                'count' => $count,
                'average' => $average,
                'reservations' => $reservationTotal,
                'memberships' => $membershipTotal,
                'other' => $otherTotal,
            ],
            'chart' => $analytics,
            'pageScripts' => ['js/rev-charts.js', 'js/revenue.js'],
        ]);
    }

    /**
     * @return array{days:list<array{date:string,label:string,amount:float,count:int}>,breakdown:array{reservations:float,memberships:float,other:float},total:float,count:int}
     */
    private function revenueAnalytics(\DateTimeImmutable $fromLocal, \DateTimeImmutable $toExclusiveLocal): array
    {
        $days = [];
        for ($cursor = $fromLocal; $cursor < $toExclusiveLocal; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = [
                'date' => $key,
                'label' => $cursor->format('j.n.'),
                'amount' => 0.0,
                'count' => 0,
            ];
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT amount, paid_at, reservation_id, membership_id, provider
             FROM payments
             WHERE ' . PaymentService::revenueSql() . ' AND paid_at >= :a AND paid_at < :b',
            [
                'a' => Clock::toUtc($fromLocal)->format('Y-m-d H:i:s'),
                'b' => Clock::toUtc($toExclusiveLocal)->format('Y-m-d H:i:s'),
            ]
        );

        $breakdown = ['reservations' => 0.0, 'memberships' => 0.0, 'other' => 0.0];
        $total = 0.0;
        foreach ($rows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $total += $amount;
            $dayKey = Clock::toLocal((string) $row['paid_at'])->format('Y-m-d');
            if (isset($days[$dayKey])) {
                $days[$dayKey]['amount'] += $amount;
                $days[$dayKey]['count']++;
            }
            if (!empty($row['reservation_id'])) {
                $breakdown['reservations'] += $amount;
            } elseif (!empty($row['membership_id'])) {
                $breakdown['memberships'] += $amount;
            } else {
                $breakdown['other'] += $amount;
            }
        }

        return [
            'days' => array_values($days),
            'breakdown' => $breakdown,
            'total' => $total,
            'count' => count($rows),
        ];
    }

    /**
     * @return array{key:string,label:string,from:string,to:string,date:?string,from_date:?string,to_date:?string,start_day:string,end_day:string}
     */
    private function resolveRevenuePeriod(Request $request): array
    {
        $key = strtolower(trim((string) $request->query('obdobi', 'dnes')));
        $allowed = ['dnes', 'vcera', '7d', '30d', 'mesic', 'den', 'rozsah'];
        if (!in_array($key, $allowed, true)) {
            $key = 'dnes';
        }

        $today = Clock::nowLocal()->setTime(0, 0);
        $date = trim((string) $request->query('datum', ''));
        $fromDate = trim((string) $request->query('od', ''));
        $toDate = trim((string) $request->query('do', ''));

        $fromLocal = $today;
        $toLocal = $today->modify('+1 day');
        $label = 'Dnes';
        $startDay = $today->format('Y-m-d');
        $endDay = $today->format('Y-m-d');

        switch ($key) {
            case 'vcera':
                $fromLocal = $today->modify('-1 day');
                $toLocal = $today;
                $label = 'Včera';
                $startDay = $fromLocal->format('Y-m-d');
                $endDay = $fromLocal->format('Y-m-d');
                break;
            case '7d':
                $fromLocal = $today->modify('-6 days');
                $toLocal = $today->modify('+1 day');
                $label = 'Posledních 7 dní';
                $startDay = $fromLocal->format('Y-m-d');
                $endDay = $today->format('Y-m-d');
                break;
            case '30d':
                $fromLocal = $today->modify('-29 days');
                $toLocal = $today->modify('+1 day');
                $label = 'Posledních 30 dní';
                $startDay = $fromLocal->format('Y-m-d');
                $endDay = $today->format('Y-m-d');
                break;
            case 'mesic':
                $fromLocal = $today->modify('first day of this month')->setTime(0, 0);
                $toLocal = $fromLocal->modify('first day of next month');
                $label = 'Tento měsíc';
                $startDay = $fromLocal->format('Y-m-d');
                $endDay = $toLocal->modify('-1 day')->format('Y-m-d');
                break;
            case 'den':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    try {
                        $fromLocal = Clock::parseLocal($date . ' 00:00:00');
                        $toLocal = $fromLocal->modify('+1 day');
                        $label = 'Den ' . $fromLocal->format('j. n. Y');
                        $startDay = $fromLocal->format('Y-m-d');
                        $endDay = $startDay;
                    } catch (\Exception) {
                        $key = 'dnes';
                        $fromLocal = $today;
                        $toLocal = $today->modify('+1 day');
                        $label = 'Dnes';
                        $date = '';
                        $startDay = $today->format('Y-m-d');
                        $endDay = $startDay;
                    }
                } else {
                    $key = 'dnes';
                    $date = '';
                    $label = 'Dnes';
                }
                break;
            case 'rozsah':
                if (
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) === 1
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) === 1
                ) {
                    try {
                        $fromLocal = Clock::parseLocal($fromDate . ' 00:00:00');
                        $endLocal = Clock::parseLocal($toDate . ' 00:00:00');
                        if ($endLocal < $fromLocal) {
                            [$fromLocal, $endLocal] = [$endLocal, $fromLocal];
                            $fromDate = $fromLocal->format('Y-m-d');
                            $toDate = $endLocal->format('Y-m-d');
                        }
                        $toLocal = $endLocal->modify('+1 day');
                        $label = $fromLocal->format('j. n. Y') . ' – ' . $endLocal->format('j. n. Y');
                        $startDay = $fromLocal->format('Y-m-d');
                        $endDay = $endLocal->format('Y-m-d');
                    } catch (\Exception) {
                        $key = 'dnes';
                        $fromLocal = $today;
                        $toLocal = $today->modify('+1 day');
                        $fromDate = '';
                        $toDate = '';
                        $label = 'Dnes';
                        $startDay = $today->format('Y-m-d');
                        $endDay = $startDay;
                    }
                } else {
                    $key = 'dnes';
                    $fromDate = '';
                    $toDate = '';
                    $label = 'Dnes';
                }
                break;
            default:
                $key = 'dnes';
                $label = 'Dnes';
                break;
        }

        return [
            'key' => $key,
            'label' => $label,
            'from' => Clock::toUtc($fromLocal)->format('Y-m-d H:i:s'),
            'to' => Clock::toUtc($toLocal)->format('Y-m-d H:i:s'),
            'date' => $date !== '' ? $date : null,
            'from_date' => $fromDate !== '' ? $fromDate : null,
            'to_date' => $toDate !== '' ? $toDate : null,
            'start_day' => $startDay,
            'end_day' => $endDay,
        ];
    }

    public function exportInterest(): never
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT email, source, ip_address, created_at FROM interest_signups ORDER BY created_at DESC'
        );
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new HttpException(500, 'Export se nepodařilo připravit.');
        }
        fputcsv($handle, ['email', 'zdroj', 'ip', 'vytvořeno']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['email'],
                $row['source'],
                $row['ip_address'] ?? '',
                $row['created_at'],
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        Response::download('privofit-zajem.csv', $csv, 'text/csv; charset=UTF-8');
    }
}
