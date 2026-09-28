<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Billing\CheckoutService;
use App\Services\MembershipService;
use App\Services\ReservationService;
use App\Support\Clock;

final class ReservationController extends Controller
{
    public function index(Request $request): never
    {
        $user = $this->requireUser();
        $service = ReservationService::make($this->app->db());
        $date = (string) $request->query('date', Clock::nowLocal()->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Clock::nowLocal()->format('Y-m-d');
        }
        $rooms = $service->activeRooms();
        $room = $service->roomByPublicId((string) $request->query('room', '')) ?? ($rooms[0] ?? null);
        $roomId = $room ? (int) $room['id'] : null;
        $memberships = new MembershipService($this->app->db());
        $membership = $memberships->activeForUser((int) $user['id']);
        $covers = $memberships->coversBooking($membership);
        $entriesRemaining = 0;
        if ($membership) {
            $entriesRemaining = $membership['entries_remaining'] === null ? null : (int) $membership['entries_remaining'];
        }
        $this->view('user/reservations', [
            'title' => 'Rezervace',
            'availability' => $service->availability($date, $roomId, (int) $user['id']),
            'date' => $date,
            'today' => Clock::nowLocal()->format('Y-m-d'),
            'membership_covers' => $covers,
            'entries_remaining' => $entriesRemaining,
            'rooms' => $rooms,
            'room' => $room,
            'pageScripts' => ['js/reservations.js'],
        ]);
    }

    public function availability(Request $request): never
    {
        $user = $this->requireUser();
        $service = ReservationService::make($this->app->db());
        $date = (string) $request->query('date', Clock::nowLocal()->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->jsonError('Neplatné datum.', 422);
        }
        $room = $service->roomByPublicId((string) $request->query('room', ''));
        $this->jsonOk($service->availability($date, $room ? (int) $room['id'] : null, (int) $user['id']));
    }

    public function calendar(Request $request): never
    {
        $user = $this->requireUser();
        $service = ReservationService::make($this->app->db());
        $year = (int) $request->query('year', Clock::nowLocal()->format('Y'));
        $month = (int) $request->query('month', Clock::nowLocal()->format('n'));
        $room = $service->roomByPublicId((string) $request->query('room', ''));
        $this->jsonOk([
            'year' => $year,
            'month' => $month,
            'days' => $service->monthOverview($year, $month, $room ? (int) $room['id'] : null, (int) $user['id']),
        ]);
    }

    public function store(Request $request): never
    {
        $user = $this->requireUser();
        $service = ReservationService::make($this->app->db());
        $start = (string) $request->input('start');
        $date = preg_match('/^(\d{4}-\d{2}-\d{2})/', $start, $match) ? $match[1] : '';
        $room = $service->roomByPublicId((string) $request->input('room', ''));
        $roomQuery = $room ? '&room=' . rawurlencode((string) $room['public_id']) : '';
        $back = '/user/rezervace' . ($date !== '' ? '?date=' . rawurlencode($date) . $roomQuery : '');
        $duration = (int) $request->input('duration', 60);
        $pay = in_array((string) $request->input('pay', '0'), ['1', 'true', 'pay'], true);
        $rawStarts = $request->input('starts', []);
        $starts = is_array($rawStarts) ? $rawStarts : [];
        $membership = (new MembershipService($this->app->db()))->activeForUser((int) $user['id']);
        $unlimited = $membership && $membership['entries_remaining'] === null;
        try {
            $guestCount = (int) $request->input('guests', 1);
            $roomId = $room ? (int) $room['id'] : null;
            $created = $starts !== []
                ? $service->createMany($user, $starts, $guestCount, $roomId, $pay)
                : [$service->create($user, $start, $duration, $guestCount, $roomId, $pay)];
            $needsPay = false;
            $blocks = 0;
            foreach ($created as $reservation) {
                $blocks += $this->blocksOf($reservation);
                if (($reservation['status'] ?? '') !== 'confirmed' && (float) ($reservation['price'] ?? 0) > 0) {
                    $needsPay = true;
                }
            }
            if (!$needsPay) {
                $covered = false;
                foreach ($created as $reservation) {
                    if (!empty($reservation['membership_id'])) {
                        $covered = true;
                        break;
                    }
                }
                $this->flashSuccess($covered
                    ? $this->membershipBookedMessage(max(1, $blocks), $unlimited)
                    : (count($created) > 1 ? 'Rezervace jsou potvrzené.' : 'Rezervace je potvrzená.'));
                if ($request->wantsJson()) {
                    $this->jsonOk(['redirect' => $this->app->url($back)]);
                }
                $this->redirect($back);
            }
            $url = CheckoutService::make($this->app->db())->startBundle($user, $created, $this->app);
            if ($request->wantsJson()) {
                $this->jsonOk(['checkout_url' => $url]);
            }
            Response::redirect($url);
        } catch (HttpException $e) {
            if ($request->wantsJson()) {
                $this->jsonError($e->getMessage(), $e->status);
            }
            $this->flashError($e->getMessage());
            $this->redirect($back);
        }
    }

    public function paid(Request $request): never
    {
        $user = $this->requireUser();
        $sessionId = trim((string) $request->query('session_id', ''));
        try {
            $payment = CheckoutService::make($this->app->db())->fulfillSession($sessionId);
            if ((int) ($payment['user_id'] ?? 0) !== (int) $user['id']) {
                throw new HttpException(403, 'Tato platba nepatří k tvému účtu.');
            }
            $this->flashSuccess('Platba prošla a rezervace je potvrzená.');
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/moje-rezervace');
    }

    public function checkoutCancel(Request $request): never
    {
        $user = $this->requireUser();
        $paymentId = trim((string) $request->query('platba', ''));
        if ($paymentId !== '') {
            CheckoutService::make($this->app->db())->cancelHold($paymentId, $user);
        }
        $this->flashError('Platba se nedokončila. Termín se uvolnil, můžeš ho vybrat znovu.');
        $date = (string) $request->query('date', '');
        $this->redirect('/user/rezervace' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? '?date=' . rawurlencode($date) : ''));
    }

    public function cancel(Request $request, array $params): never
    {
        $user = $this->requireUser();
        try {
            $outcome = ReservationService::make($this->app->db())->cancel($user, (string) $params['id']);
            $this->flashSuccess(match ($outcome) {
                'refunded' => 'Rezervace byla zrušena. Peníze se vrací.',
                'late' => 'Rezervace byla zrušena. Na vrácení peněz už není nárok.',
                'entry' => 'Rezervace byla zrušena. Vstup se vrátil do členství.',
                'entries' => 'Rezervace byla zrušena. Vstupy se vrátily do členství.',
                default => 'Rezervace byla zrušena.',
            });
        } catch (HttpException $e) {
            $this->flashError($e->getMessage());
        }
        $this->redirect('/user/moje-rezervace?stav=zrusene');
    }

    public function mine(Request $request): never
    {
        $user = $this->requireUser();
        $filter = (string) $request->query('stav', 'prehled');
        $allowed = ['prehled', 'naplanovane', 'probehle', 'zrusene', 'platba', 'vse'];
        if (!in_array($filter, $allowed, true)) {
            $filter = 'prehled';
        }
        $now = Clock::nowUtc();
        $groups = [
            'naplanovane' => [],
            'probehle' => [],
            'zrusene' => [],
            'platba' => [],
        ];
        foreach (ReservationService::make($this->app->db())->forUser((int) $user['id']) as $row) {
            $status = (string) ($row['status'] ?? '');
            $end = new \DateTimeImmutable((string) $row['ends_at'], new \DateTimeZone('UTC'));
            $upcoming = $end >= $now;
            if ($status === 'cancelled') {
                $groups['zrusene'][] = $row;
            } elseif ($status === 'pending_payment') {
                $groups['platba'][] = $row;
                $groups['naplanovane'][] = $row;
            } elseif (!$upcoming || in_array($status, ['completed', 'no_show', 'expired'], true)) {
                $groups['probehle'][] = $row;
            } else {
                $groups['naplanovane'][] = $row;
            }
        }
        usort($groups['naplanovane'], static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        usort($groups['platba'], static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        usort($groups['probehle'], static fn (array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));
        usort($groups['zrusene'], static fn (array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

        $order = match ($filter) {
            'naplanovane', 'probehle', 'zrusene', 'platba' => [$filter],
            'vse' => ['naplanovane', 'probehle', 'zrusene'],
            default => ['naplanovane', 'probehle'],
        };
        $titles = [
            'naplanovane' => 'Naplánované',
            'probehle' => 'Proběhlé',
            'zrusene' => 'Zrušené',
            'platba' => 'Čeká na platbu',
        ];
        $empty = [
            'naplanovane' => 'Nemáš naplánovaný termín.',
            'probehle' => 'Zatím tu nic neproběhlo.',
            'zrusene' => 'Nemáš zrušenou rezervaci.',
            'platba' => 'Nic nečeká na platbu.',
        ];
        $sections = [];
        foreach ($order as $key) {
            $sections[] = [
                'key' => $key,
                'title' => $titles[$key],
                'empty' => $empty[$key],
                'items' => $groups[$key],
            ];
        }
        $paidAt = [];
        foreach ($this->app->db()->fetchAll(
            "SELECT reservation_id, paid_at FROM payments WHERE user_id = :uid AND status = 'paid' AND reservation_id IS NOT NULL AND paid_at IS NOT NULL",
            ['uid' => (int) $user['id']]
        ) as $payment) {
            $stamp = new \DateTimeImmutable((string) $payment['paid_at'], new \DateTimeZone('UTC'));
            $paidAt[(int) $payment['reservation_id']] = $stamp->getTimestamp();
        }
        $this->view('user/mine', [
            'title' => 'Moje rezervace',
            'filter' => $filter,
            'paidAt' => $paidAt,
            'refundSeconds' => ReservationService::REFUND_SECONDS,
            'nowUnix' => Clock::nowUtc()->getTimestamp(),
            'pageScripts' => ['js/mine.js'],
            'counts' => [
                'prehled' => count($groups['naplanovane']) + count($groups['probehle']),
                'naplanovane' => count($groups['naplanovane']),
                'probehle' => count($groups['probehle']),
                'zrusene' => count($groups['zrusene']),
                'platba' => count($groups['platba']),
                'vse' => count($groups['naplanovane']) + count($groups['probehle']) + count($groups['zrusene']),
            ],
            'sections' => $sections,
        ]);
    }

    /** @param array<string, mixed> $reservation */
    private function blocksOf(array $reservation): int
    {
        $start = new \DateTimeImmutable((string) $reservation['starts_at'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable((string) $reservation['ends_at'], new \DateTimeZone('UTC'));
        $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        $buffer = max(0, (int) ($reservation['buffer_minutes'] ?? 0));
        $block = 60 + $buffer;
        if ($block < 1) {
            return 1;
        }
        return max(1, (int) round(($minutes + $buffer) / $block));
    }

    private function membershipBookedMessage(int $blocks, bool $unlimited): string
    {
        if ($unlimited) {
            return 'Rezervace je potvrzená. Platí tvoje členství.';
        }
        if ($blocks === 1) {
            return 'Rezervace je potvrzená. Odečetl se 1 vstup z členství.';
        }
        if ($blocks <= 4) {
            return 'Rezervace je potvrzená. Odečetly se ' . $blocks . ' vstupy z členství.';
        }
        return 'Rezervace je potvrzená. Odečetlo se ' . $blocks . ' vstupů z členství.';
    }
}
