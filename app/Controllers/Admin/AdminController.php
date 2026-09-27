<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
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
        $loaded = $this->loadDashboard();
        $live = $this->presentDashboard($loaded['stats'], $loaded['chart']);

        $this->view('admin/dashboard', [
            'title' => 'Přehled správy',
            'stats' => $loaded['stats'],
            'chart' => $loaded['chart'],
            'liveRev' => $this->dashboardRevision($live),
            'pageScripts' => ['js/rev-charts.js', 'js/admin-dash.js', 'js/customers.js'],
        ]);
    }

    public function dashboardLive(): never
    {
        if (is_admin_user() && admin_view_mode() === 'user') {
            $this->jsonError('Přehled správy je v uživatelském režimu skrytý.', 403);
        }
        $loaded = $this->loadDashboard(false);
        $live = $this->presentDashboard($loaded['stats'], $loaded['chart']);
        $live['rev'] = $this->dashboardRevision($live);
        $this->jsonOk($live);
    }

    /**
     * @return array{stats: array<string, mixed>, chart: array<string, mixed>}
     */
    private function loadDashboard(bool $probeDoor = true): array
    {
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
            'active_members' => (int) $db->fetchColumn(
                "SELECT COUNT(DISTINCT user_id) FROM memberships
                 WHERE status = 'active' AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP())"
            ),
            'today_reservations' => count($todayReservations),
            'current' => ReservationService::make($db)->occupancyNow(),
            'next' => $next,
            'today_list' => $todayReservations,
            'notices' => $this->dashboardNotices($db),
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
            'door' => $probeDoor
                ? AccessControlService::make($db)->doorStatus()
                : ['configured' => false],
            'interest' => 0,
            'customers' => (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role = \'user\''),
        ];
        try {
            $stats['interest'] = (int) $db->fetchColumn('SELECT COUNT(*) FROM interest_signups');
        } catch (\PDOException) {
        }

        $chartFrom = Clock::nowLocal()->modify('-29 days')->setTime(0, 0);
        $chartTo = Clock::nowLocal()->setTime(0, 0)->modify('+1 day');

        return [
            'stats' => $stats,
            'chart' => $this->revenueAnalytics($chartFrom, $chartTo),
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $chart
     * @return array<string, mixed>
     */
    private function presentDashboard(array $stats, array $chart): array
    {
        $nowLocal = Clock::nowLocal();
        $dayNames = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];
        $current = is_array($stats['current'] ?? null) ? $stats['current'] : [];
        $reservation = is_array($current['reservation'] ?? null) ? $current['reservation'] : null;
        $occupied = !empty($current['occupied']);
        $next = is_array($stats['next'] ?? null) ? $stats['next'] : null;
        $todayList = is_array($stats['today_list'] ?? null) ? $stats['today_list'] : [];

        $guestName = '';
        $slotLabel = '';
        if ($reservation) {
            $guestName = trim((string) (($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? '')));
            if (!empty($reservation['starts_at']) && !empty($reservation['ends_at'])) {
                $start = Clock::toLocal((string) $reservation['starts_at']);
                $end = $this->blockEnd((string) $reservation['ends_at'], $reservation['buffer_minutes'] ?? 15);
                $endClock = $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : $end->format('j. n. H:i');
                $slotLabel = $start->format('H:i') . '–' . $endClock;
            }
        }

        $nextLabel = '';
        if ($next && !empty($next['starts_at'])) {
            $ns = Clock::toLocal((string) $next['starts_at']);
            $nextName = trim((string) (($next['first_name'] ?? '') . ' ' . ($next['last_name'] ?? '')));
            $nextWhen = $ns->format('Y-m-d') === $nowLocal->format('Y-m-d')
                ? $ns->format('H:i')
                : ($dayNames[(int) $ns->format('N')] ?? '') . ' ' . $ns->format('j. n.') . ' ' . $ns->format('H:i');
            $nextLabel = $nextWhen . ($nextName !== '' ? ' · ' . $nextName : '');
        }

        $detail = $occupied
            ? ($guestName !== '' ? $guestName . ($slotLabel !== '' ? ' · ' . $slotLabel : '') : 'Aktivní rezervace')
            : ($nextLabel !== '' ? 'Další: ' . $nextLabel : 'Žádný další termín');

        $items = [];
        foreach ($todayList as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rs = Clock::toLocal((string) $row['starts_at']);
            $re = $this->blockEnd((string) $row['ends_at'], $row['buffer_minutes'] ?? 15);
            $endClock = $re->format('Y-m-d') === $rs->format('Y-m-d') ? $re->format('H:i') : $re->format('j. n. H:i');
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            if ($name === '') {
                $name = (string) ($row['username'] ?? 'Zákazník');
            }
            $isLive = $occupied && $reservation && (int) ($reservation['id'] ?? 0) === (int) ($row['id'] ?? 0);
            $isPast = $re < $nowLocal;
            $pending = ($row['status'] ?? '') === 'pending_payment';
            $publicId = (string) ($row['user_public_id'] ?? '');
            $reservationId = (string) ($row['public_id'] ?? '');
            $canCancel = in_array((string) ($row['status'] ?? ''), ['confirmed', 'pending_payment'], true) && $reservationId !== '';
            if ($isLive) {
                $badge = 'Teď';
                $badgeClass = 'badge-warn';
            } elseif ($pending) {
                $badge = 'Platba';
                $badgeClass = 'badge-muted';
            } elseif ($isPast) {
                $badge = 'Hotovo';
                $badgeClass = 'badge-done';
            } else {
                $badge = 'Čeká';
                $badgeClass = 'badge-ok';
            }
            $items[] = [
                'time' => $rs->format('H:i'),
                'end' => $endClock,
                'name' => $name,
                'href' => $publicId !== '' ? url('/user/sprava/zakaznici/' . $publicId) : '',
                'meta' => (string) ($row['room_name'] ?? 'Studio') . ($pending ? ' · čeká na platbu' : ''),
                'live' => $isLive,
                'past' => $isPast,
                'badge' => $badge,
                'badge_class' => $badgeClass,
                'cancel_url' => $canCancel ? url('/user/sprava/rezervace/' . $reservationId . '/zrusit') : '',
                'cancel_name' => $name . ' · ' . $rs->format('H:i') . '–' . $endClock,
            ];
        }

        $notices = [];
        foreach (is_array($stats['notices'] ?? null) ? $stats['notices'] : [] as $note) {
            if (!is_array($note)) {
                continue;
            }
            $notices[] = [
                'key' => (string) ($note['key'] ?? ''),
                'tone' => (string) ($note['tone'] ?? ''),
                'href' => (string) ($note['href'] ?? ''),
                'avatar' => (string) ($note['avatar'] ?? ''),
                'initials' => (string) ($note['initials'] ?? 'P'),
                'title' => (string) ($note['title'] ?? ''),
                'kind' => (string) ($note['kind'] ?? ''),
                'kind_class' => (string) ($note['kind_class'] ?? ''),
                'when' => (string) ($note['when'] ?? ''),
                'text' => (string) ($note['text'] ?? ''),
            ];
        }

        $days = [];
        foreach (is_array($chart['days'] ?? null) ? $chart['days'] : [] as $day) {
            if (!is_array($day)) {
                continue;
            }
            $rawParts = $day['parts'] ?? [];
            if ($rawParts instanceof \stdClass) {
                $rawParts = (array) $rawParts;
            }
            $parts = [];
            if (is_array($rawParts)) {
                foreach ($rawParts as $key => $value) {
                    $parts[(string) $key] = round((float) $value, 2);
                }
            }
            $days[] = [
                'date' => (string) ($day['date'] ?? ''),
                'label' => (string) ($day['label'] ?? ''),
                'amount' => round((float) ($day['amount'] ?? 0), 2),
                'count' => (int) ($day['count'] ?? 0),
                'reservations' => round((float) ($day['reservations'] ?? 0), 2),
                'memberships' => round((float) ($day['memberships'] ?? 0), 2),
                'other' => round((float) ($day['other'] ?? 0), 2),
                'parts' => (object) $parts,
            ];
        }

        $segments = [];
        foreach (is_array($chart['segments'] ?? null) ? $chart['segments'] : [] as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $segments[] = [
                'key' => (string) ($segment['key'] ?? ''),
                'label' => (string) ($segment['label'] ?? ''),
                'color' => (string) ($segment['color'] ?? ''),
                'amount' => round((float) ($segment['amount'] ?? 0), 2),
                'amount_label' => money_format_czk($segment['amount'] ?? 0),
            ];
        }

        $breakdown = is_array($chart['breakdown'] ?? null) ? $chart['breakdown'] : [];

        return [
            'clock' => ($dayNames[(int) $nowLocal->format('N')] ?? '') . ' ' . $nowLocal->format('j. n. Y') . ' · ' . $nowLocal->format('H:i'),
            'occupancy' => [
                'occupied' => $occupied,
                'status' => $occupied ? 'Obsazeno' : 'Volno',
                'detail' => $detail,
            ],
            'kpis' => [
                'today_reservations' => (int) ($stats['today_reservations'] ?? 0),
                'entries' => (int) ($stats['entries'] ?? 0),
                'failed_access' => (int) ($stats['failed_access'] ?? 0),
                'active_members' => (int) ($stats['active_members'] ?? 0),
                'customers' => (int) ($stats['customers'] ?? 0),
                'interest' => (int) ($stats['interest'] ?? 0),
            ],
            'money' => [
                'total' => money_format_czk($chart['total'] ?? $stats['revenue'] ?? 0),
                'today' => money_format_czk($stats['revenue_today'] ?? 0),
                'yesterday' => money_format_czk($stats['revenue_yesterday'] ?? 0),
                'count' => (int) ($stats['revenue_count_30'] ?? ($chart['count'] ?? 0)),
            ],
            'chart' => [
                'days' => $days,
                'breakdown' => [
                    'reservations' => round((float) ($breakdown['reservations'] ?? 0), 2),
                    'memberships' => round((float) ($breakdown['memberships'] ?? 0), 2),
                    'other' => round((float) ($breakdown['other'] ?? 0), 2),
                ],
                'segments' => $segments,
                'total' => round((float) ($chart['total'] ?? 0), 2),
                'count' => (int) ($chart['count'] ?? 0),
            ],
            'schedule' => [
                'count' => count($items),
                'now' => ($occupied && $reservation) ? [
                    'name' => $guestName !== '' ? $guestName : 'Zákazník',
                    'slot' => $slotLabel !== '' ? $slotLabel : 'Probíhající termín',
                ] : null,
                'empty' => $items === []
                    ? 'Dnes žádné rezervace.' . ($nextLabel !== '' ? ' Další termín ' . $nextLabel . '.' : '')
                    : '',
                'items' => $items,
            ],
            'notices' => $notices,
        ];
    }

    /** @param array<string, mixed> $live */
    private function dashboardRevision(array $live): string
    {
        $stamp = $live;
        unset($stamp['clock'], $stamp['rev']);
        $encoded = json_encode($stamp, JSON_UNESCAPED_UNICODE);
        return substr(hash('sha256', is_string($encoded) ? $encoded : ''), 0, 16);
    }

    private function blockEnd(string $endsAt, mixed $buffer): \DateTimeImmutable
    {
        return Clock::toLocal($endsAt)->modify('+' . max(0, (int) $buffer) . ' minutes');
    }

    /** @return list<array<string, mixed>> */
    private function dashboardNotices(Database $db): array
    {
        $nowLocal = Clock::nowLocal();
        $todayKey = $nowLocal->format('Y-m-d');
        $weekStart = Clock::toUtc($nowLocal->modify('-6 days')->setTime(0, 0))->format('Y-m-d H:i:s');
        $now = Clock::utc();
        $stamp = static function (string $utc) use ($todayKey): array {
            $local = Clock::toLocal($utc);
            return [
                'at' => $local->getTimestamp(),
                'when' => $local->format('Y-m-d') === $todayKey ? $local->format('H:i') : $local->format('j. n. H:i'),
            ];
        };
        $person = static function (array $row): array {
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            if ($name === '') {
                $name = (string) ($row['username'] ?? $row['email'] ?? 'Neznámý');
            }
            $first = (string) ($row['first_name'] ?? '');
            $last = (string) ($row['last_name'] ?? '');
            $initials = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
            if ($initials === '') {
                $initials = mb_strtoupper(mb_substr($name, 0, 1)) ?: 'P';
            }
            $publicId = (string) ($row['public_id'] ?? '');
            $avatar = null;
            if ($publicId !== '' || !empty($row['avatar_path'])) {
                $avatar = avatar_url([
                    'avatar_path' => $row['avatar_path'] ?? null,
                    'public_id' => $publicId !== '' ? $publicId : 'guest',
                    'first_name' => $first,
                    'last_name' => $last,
                ]);
            }

            return [
                'title' => $name,
                'initials' => mb_substr($initials, 0, 2),
                'avatar' => $avatar,
                'href' => $publicId !== '' ? url('/user/sprava/zakaznici/' . $publicId) : null,
            ];
        };
        $reasons = [
            'admin_open' => 'Správce otevřel dveře',
            'admin_close' => 'Správce zavřel dveře',
            'unverified' => 'Účet nemá ověřený e-mail',
            'inactive' => 'Účet není aktivní',
            'no_reservation' => 'Vstup bez platné rezervace',
            'no_door' => 'Chybí nastavené dveře',
            'no_permission' => 'Nemá oprávnění ke vstupu',
            'rate_limited' => 'Příliš mnoho pokusů o vstup',
            'door_busy' => 'Zámek byl zaneprázdněný',
            'not_configured' => 'Vstup není nastavený',
        ];
        $notices = [];

        $denied = $db->fetchAll(
            "SELECT l.created_at, l.denial_reason, u.username, u.first_name, u.last_name, u.public_id, u.avatar_path
             FROM access_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.authorization_result = 'denied' AND l.created_at >= :a
             ORDER BY l.created_at DESC
             LIMIT 6",
            ['a' => $weekStart]
        );
        foreach ($denied as $row) {
            $who = $person($row);
            $reasonKey = (string) ($row['denial_reason'] ?? '');
            $notices[] = array_merge($who, $stamp((string) $row['created_at']), [
                'key' => 'entry:' . ((string) ($row['public_id'] ?? 'anon')) . ':' . (string) $row['created_at'],
                'text' => $reasons[$reasonKey] ?? ($reasonKey !== '' ? $reasonKey : 'Vstup byl zamítnut'),
                'kind' => 'Vstup',
                'kind_class' => 'is-entry',
                'tone' => 'bad',
                'href' => $who['href'] ?? url('/user/sprava/vstup'),
            ]);
        }

        $pending = $db->fetchAll(
            "SELECT r.public_id AS reservation_id, r.created_at, r.starts_at, r.price, rm.name AS room_name,
                    u.first_name, u.last_name, u.username, u.public_id, u.avatar_path
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN rooms rm ON rm.id = r.room_id
             WHERE r.status = 'pending_payment' AND r.ends_at >= :now
             ORDER BY r.created_at DESC
             LIMIT 6",
            ['now' => $now]
        );
        foreach ($pending as $row) {
            $who = $person($row);
            $start = Clock::toLocal((string) $row['starts_at']);
            $room = trim((string) ($row['room_name'] ?? ''));
            $notices[] = array_merge($who, $stamp((string) $row['created_at']), [
                'key' => 'res:' . (string) ($row['reservation_id'] ?? $row['created_at']) . ':pending',
                'text' => 'Čeká na platbu ' . money_format_czk($row['price'] ?? 0) . ' · ' . $start->format('j. n. H:i') . ($room !== '' ? ' · ' . $room : ''),
                'kind' => 'Rezervace',
                'kind_class' => 'is-book',
                'tone' => 'warn',
                'href' => $this->noticePlaceUrl('/user/sprava/rezervace', ['stav' => 'vse', 'q' => (string) ($row['username'] ?: ($row['last_name'] ?? ''))], $who['href'] ?? null),
            ]);
        }

        $booked = $db->fetchAll(
            "SELECT r.public_id AS reservation_id, r.created_at, r.starts_at, rm.name AS room_name,
                    u.first_name, u.last_name, u.username, u.public_id, u.avatar_path
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN rooms rm ON rm.id = r.room_id
             WHERE r.status = 'confirmed' AND r.created_at >= :a
             ORDER BY r.created_at DESC
             LIMIT 6",
            ['a' => $weekStart]
        );
        foreach ($booked as $row) {
            $who = $person($row);
            $start = Clock::toLocal((string) $row['starts_at']);
            $room = trim((string) ($row['room_name'] ?? ''));
            $notices[] = array_merge($who, $stamp((string) $row['created_at']), [
                'key' => 'res:' . (string) ($row['reservation_id'] ?? $row['created_at']) . ':confirmed',
                'text' => 'Nová rezervace · ' . $start->format('j. n. H:i') . ($room !== '' ? ' · ' . $room : ''),
                'kind' => 'Rezervace',
                'kind_class' => 'is-book',
                'tone' => '',
                'href' => $this->noticePlaceUrl('/user/sprava/rezervace', ['stav' => 'vse', 'q' => (string) ($row['username'] ?: ($row['last_name'] ?? ''))], $who['href'] ?? null),
            ]);
        }

        $payments = $db->fetchAll(
            "SELECT p.public_id AS payment_id, p.created_at, p.paid_at, p.amount, p.status,
                    u.first_name, u.last_name, u.username, u.public_id, u.avatar_path
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status IN ('paid', 'failed', 'refunded') AND p.created_at >= :a
             ORDER BY p.created_at DESC
             LIMIT 8",
            ['a' => $weekStart]
        );
        foreach ($payments as $row) {
            $who = $person($row);
            $amount = money_format_czk($row['amount'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            $text = match ($status) {
                'failed' => 'Platba ' . $amount . ' se nezdařila',
                'refunded' => 'Platba ' . $amount . ' byla vrácena',
                default => 'Přišla platba ' . $amount,
            };
            $at = (string) (($row['paid_at'] ?? '') !== '' && $row['paid_at'] !== null ? $row['paid_at'] : $row['created_at']);
            $notices[] = array_merge($who, $stamp($at), [
                'key' => 'pay:' . (string) ($row['payment_id'] ?? $at),
                'text' => $text,
                'kind' => 'Platba',
                'kind_class' => 'is-pay',
                'tone' => $status === 'paid' ? '' : 'bad',
                'href' => $who['href'] ?? url('/user/sprava/trzby'),
            ]);
        }

        $customers = $db->fetchAll(
            "SELECT created_at, first_name, last_name, username, email, public_id, avatar_path
             FROM users
             WHERE deleted_at IS NULL AND role = 'user' AND created_at >= :a
             ORDER BY created_at DESC
             LIMIT 5",
            ['a' => $weekStart]
        );
        foreach ($customers as $row) {
            $who = $person($row);
            $notices[] = array_merge($who, $stamp((string) $row['created_at']), [
                'key' => 'user:' . (string) ($row['public_id'] ?? $row['created_at']),
                'text' => 'Založil zákaznický účet',
                'kind' => 'Zákazník',
                'kind_class' => 'is-user',
                'tone' => '',
            ]);
        }

        try {
            $leads = $db->fetchAll(
                'SELECT email, created_at FROM interest_signups WHERE created_at >= :a ORDER BY created_at DESC LIMIT 5',
                ['a' => $weekStart]
            );
            foreach ($leads as $row) {
                $email = (string) ($row['email'] ?? '');
                $local = strstr($email, '@', true) ?: $email;
                $notices[] = array_merge($stamp((string) $row['created_at']), [
                    'key' => 'lead:' . strtolower($email),
                    'title' => $email !== '' ? $email : 'Neznámý e-mail',
                    'initials' => mb_strtoupper(mb_substr($local, 0, 2)) ?: 'Z',
                    'avatar' => null,
                    'href' => $email !== '' ? url('/user/sprava/zajem?q=' . rawurlencode($email)) : url('/user/sprava/zajem'),
                    'text' => 'Zapsal se mezi zájemce',
                    'kind' => 'Zájem',
                    'kind_class' => 'is-lead',
                    'tone' => '',
                ]);
            }
        } catch (\PDOException) {
        }

        usort($notices, static fn (array $a, array $b): int => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));

        return array_slice($notices, 0, 14);
    }

    /** @param array<string, string> $query */
    private function noticePlaceUrl(string $path, array $query, ?string $fallback): string
    {
        $query = array_filter($query, static fn (string $value): bool => $value !== '');
        if ($query === []) {
            return $fallback ?: url($path);
        }

        return url($path . '?' . http_build_query($query));
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
        $paymentColumns = (new PaymentService($db))->paymentColumnSet();
        $paymentSelect = 'p.id AS payment_id, p.provider AS payment_provider, p.status AS payment_status,
                       p.amount AS payment_amount, p.provider_reference';
        foreach (['fee_amount', 'charged_amount', 'stripe_details'] as $column) {
            if (isset($paymentColumns[$column])) {
                $paymentSelect .= ', p.' . $column;
            }
        }

        $sql = "SELECT r.public_id, r.starts_at, r.ends_at, r.buffer_minutes, r.status, r.guest_count, r.price,
                       r.cancellation_reason, r.membership_id,
                       u.first_name, u.last_name, u.username, u.email, u.public_id AS user_public_id,
                       rm.name AS room_name,
                       {$paymentSelect}
                FROM reservations r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN rooms rm ON rm.id = r.room_id
                LEFT JOIN payments p ON p.id = (
                    SELECT MAX(p2.id) FROM payments p2 WHERE p2.reservation_id = r.id
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
        if (isset($paymentColumns['metadata_json'])) {
            $rows = $this->attachMetadataPayments($db, $rows, $paymentColumns);
        }
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
        if ($stubs !== [] && isset($paymentColumns['stripe_details'])) {
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

    /**
     * Platba za víc termínů najednou má reservation_id jen u prvního.
     * Ostatní jsou v metadata_json.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, true> $columns
     * @return list<array<string, mixed>>
     */
    private function attachMetadataPayments(Database $db, array $rows, array $columns): array
    {
        $need = [];
        foreach ($rows as $index => $row) {
            if (!empty($row['payment_id'])) {
                continue;
            }
            $publicId = (string) ($row['public_id'] ?? '');
            if (preg_match('/^[0-9a-f-]{36}$/i', $publicId) !== 1) {
                continue;
            }
            $need[$publicId] = $index;
        }
        if ($need === []) {
            return $rows;
        }

        $select = 'id, provider, status, amount, provider_reference, metadata_json';
        foreach (['fee_amount', 'charged_amount', 'stripe_details'] as $column) {
            if (isset($columns[$column])) {
                $select .= ', ' . $column;
            }
        }
        $likes = [];
        $params = [];
        $i = 0;
        foreach (array_keys($need) as $publicId) {
            $key = 'p' . $i;
            $likes[] = 'metadata_json LIKE :' . $key;
            $params[$key] = '%' . $publicId . '%';
            $i++;
        }
        $found = $db->fetchAll(
            'SELECT ' . $select . ' FROM payments WHERE metadata_json IS NOT NULL AND (' . implode(' OR ', $likes) . ') ORDER BY id DESC',
            $params
        );
        $used = [];
        foreach ($found as $payment) {
            $meta = json_decode((string) ($payment['metadata_json'] ?? ''), true);
            $ids = is_array($meta['reservations'] ?? null) ? $meta['reservations'] : [];
            foreach ($ids as $publicId) {
                $publicId = (string) $publicId;
                if (!isset($need[$publicId]) || isset($used[$publicId])) {
                    continue;
                }
                $used[$publicId] = true;
                $index = $need[$publicId];
                $rows[$index]['payment_id'] = $payment['id'];
                $rows[$index]['payment_provider'] = $payment['provider'] ?? '';
                $rows[$index]['payment_status'] = $payment['status'] ?? '';
                $rows[$index]['payment_amount'] = $payment['amount'] ?? null;
                $rows[$index]['provider_reference'] = $payment['provider_reference'] ?? '';
                if (isset($columns['stripe_details'])) {
                    $rows[$index]['stripe_details'] = $payment['stripe_details'] ?? null;
                }
            }
        }

        return $rows;
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
            'title' => 'Dveře',
            'status' => AccessControlService::make($this->app->db())->doorStatus(),
            'doors' => $this->app->db()->fetchAll('SELECT * FROM doors ORDER BY id ASC'),
            'logs' => $this->app->db()->fetchAll('SELECT l.*, u.username, u.first_name, u.last_name FROM access_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 80'),
            'pageScripts' => ['js/doors.js'],
        ]);
    }

    public function doorLive(): never
    {
        $this->jsonOk(AccessControlService::make($this->app->db())->liveDoors());
    }

    public function setDoor(Request $request): never
    {
        $actor = $this->requireUser();
        $doorId = (int) $request->input('door_id', 0);
        $open = (string) $request->input('state', '') === 'open';
        $json = $request->wantsJson();
        try {
            $access = AccessControlService::make($this->app->db());
            $result = $access->adminSet($actor, $doorId, $open, $request->ip());
            (new AuditService($this->app->db()))->log(
                (int) $actor['id'],
                $open ? 'door.open' : 'door.close',
                'door',
                $doorId,
                null,
                ['state' => $open ? 'open' : 'close'],
                $request->ip()
            );
            $message = (string) ($result['message'] ?? ($open ? 'Dveře jsou otevřené.' : 'Dveře jsou zavřené.'));
            if ($json) {
                $this->jsonOk(['door' => $access->liveDoor($doorId)], $message);
            }
            $this->flashSuccess($message);
        } catch (HttpException $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Příkaz se nepodařilo odeslat.';
            if ($json) {
                $this->jsonError($message, $e->status >= 400 ? $e->status : 400);
            }
            $this->flashError($message);
        }
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
            'reservation.max_minutes' => 1440,
            'reservation.buffer_minutes' => 15,
            'reservation.cancellation_hours' => 12,
            'reservation.advance_days' => 56,
            'access.early_minutes' => 5,
            'access.late_minutes' => 5,
        ];
        $limits = [
            'reservation.advance_days' => [1, 365],
        ];
        foreach ($keys as $key => $default) {
            $raw = $request->input($key, $request->input(str_replace('.', '_', $key), $default));
            if ($key === 'reservation.max_minutes' && trim((string) $raw) === '') {
                $this->app->settings()->set($key, '');
                continue;
            }
            $value = max(0, (int) $raw);
            if (isset($limits[$key])) {
                [$min, $max] = $limits[$key];
                $value = max($min, min($max, $value));
            }
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
            'pageScripts' => ['js/rev-charts.js', 'js/revenue.js', 'js/payment-detail.js'],
        ]);
    }

    /**
     * @return array{days:list<array{date:string,label:string,amount:float,count:int,reservations:float,memberships:float,other:float,parts:object}>,breakdown:array{reservations:float,memberships:float,other:float},segments:list<array{key:string,label:string,color:string,amount:float}>,total:float,count:int}
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
                'reservations' => 0.0,
                'memberships' => 0.0,
                'other' => 0.0,
                'parts' => new \stdClass(),
            ];
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT p.amount, p.paid_at, p.reservation_id, p.membership_id,
                    mp.slug AS plan_slug, mp.name AS plan_name, mp.type AS plan_type, mp.sort_order AS plan_sort
             FROM payments p
             LEFT JOIN memberships m ON m.id = p.membership_id
             LEFT JOIN membership_plans mp ON mp.id = m.plan_id
             WHERE ' . PaymentService::revenueSql('p') . ' AND p.paid_at >= :a AND p.paid_at < :b',
            [
                'a' => Clock::toUtc($fromLocal)->format('Y-m-d H:i:s'),
                'b' => Clock::toUtc($toExclusiveLocal)->format('Y-m-d H:i:s'),
            ]
        );

        $breakdown = ['reservations' => 0.0, 'memberships' => 0.0, 'other' => 0.0];
        $catalog = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $total += $amount;
            $dayKey = Clock::toLocal((string) $row['paid_at'])->format('Y-m-d');
            if (!empty($row['reservation_id'])) {
                $bucket = 'reservations';
                $segmentKey = 'entry';
                $segment = ['key' => $segmentKey, 'label' => 'Vstupné', 'kind' => 'entry', 'sort' => 0];
            } elseif (!empty($row['membership_id'])) {
                $bucket = 'memberships';
                $slug = strtolower(trim((string) ($row['plan_slug'] ?? '')));
                $safe = preg_replace('/[^a-z0-9_-]/', '', $slug) ?? '';
                $segmentKey = $safe !== '' ? 'plan:' . $safe : 'plan:membership';
                $label = trim((string) ($row['plan_name'] ?? ''));
                $segment = [
                    'key' => $segmentKey,
                    'label' => $label !== '' ? $label : 'Členství',
                    'kind' => (string) ($row['plan_type'] ?? 'membership'),
                    'sort' => 100 + (int) ($row['plan_sort'] ?? 0),
                ];
            } else {
                $bucket = 'other';
                $segmentKey = 'other';
                $segment = ['key' => $segmentKey, 'label' => 'Ostatní', 'kind' => 'other', 'sort' => 900];
            }
            $breakdown[$bucket] += $amount;
            if (!isset($catalog[$segmentKey])) {
                $catalog[$segmentKey] = $segment + ['amount' => 0.0];
            }
            $catalog[$segmentKey]['amount'] += $amount;
            if (isset($days[$dayKey])) {
                $days[$dayKey]['amount'] += $amount;
                $days[$dayKey]['count']++;
                $days[$dayKey][$bucket] += $amount;
                $current = $days[$dayKey]['parts']->{$segmentKey} ?? 0.0;
                $days[$dayKey]['parts']->{$segmentKey} = $current + $amount;
            }
        }

        uasort($catalog, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['label'], $b['label']));
        $usedColors = [];
        $segments = [];
        foreach ($catalog as $item) {
            if ($item['amount'] <= 0) {
                continue;
            }
            $segments[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'color' => $this->revenueSegmentColor((string) $item['kind'], $usedColors),
                'amount' => $item['amount'],
            ];
        }

        return [
            'days' => array_values($days),
            'breakdown' => $breakdown,
            'segments' => $segments,
            'total' => $total,
            'count' => count($rows),
        ];
    }

    /**
     * @param array<string, true> $used
     */
    private function revenueSegmentColor(string $kind, array &$used): string
    {
        $preferred = [
            'entry' => '#c6f21a',
            'single' => '#f0d060',
            'pack' => '#3ddc97',
            'monthly' => '#6ec8ff',
            'credit' => '#ffb86b',
            'voucher' => '#f2a0c8',
            'lifetime' => '#e0ae3a',
            'other' => '#9aa49c',
        ];
        $extras = ['#8ab4ff', '#ff8f8f', '#c9a6ff', '#7ee0d0', '#ff9f6e', '#9ad0ff'];
        $candidate = $preferred[$kind] ?? null;
        if (is_string($candidate) && !isset($used[$candidate])) {
            $used[$candidate] = true;
            return $candidate;
        }
        foreach ($extras as $color) {
            if (!isset($used[$color])) {
                $used[$color] = true;
                return $color;
            }
        }

        return '#9aa49c';
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
