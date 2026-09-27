<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Billing\PaymentService;
use App\Support\Clock;

/**
 * Návštěvnost, obsazenost a tržby pro správu.
 * Plus a minus je rozdíl čistého příjmu proti stejně dlouhému období těsně předtím.
 */
final class AdminStatsService
{
    /** @var array<int, string> */
    private const DAY_IN = [
        1 => 'v pondělí',
        2 => 'v úterý',
        3 => 've středu',
        4 => 've čtvrtek',
        5 => 'v pátek',
        6 => 'v sobotu',
        7 => 'v neděli',
    ];

    /** @var array<int, string> */
    private const DAY_SHORT = [
        1 => 'Po',
        2 => 'Út',
        3 => 'St',
        4 => 'Čt',
        5 => 'Pá',
        6 => 'So',
        7 => 'Ne',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $key): array
    {
        $period = $this->period($key);
        $money = $this->money($period);
        $flow = $this->flow($period);
        $occupancy = $this->occupancy($period['from'], $period['to'], $flow['slots']);
        $occupancyPrev = $this->occupancy($period['prev_from'], $period['from'], $flow['slots']);

        return $this->present($period, $money, $flow, $occupancy, $occupancyPrev);
    }

    /**
     * @return array{key:string,label:string,compare:string,from:\DateTimeImmutable,to:\DateTimeImmutable,prev_from:\DateTimeImmutable,start_day:string,end_day:string,tabs:array<string,string>}
     */
    private function period(string $key): array
    {
        $allowed = ['7d', '30d', 'mesic', '90d', 'rok'];
        if (!in_array($key, $allowed, true)) {
            $key = '30d';
        }

        $today = Clock::nowLocal()->setTime(0, 0);
        $to = $today->modify('+1 day');
        $from = match ($key) {
            '7d' => $today->modify('-6 days'),
            'mesic' => $today->modify('first day of this month')->setTime(0, 0),
            '90d' => $today->modify('-89 days'),
            'rok' => $today->modify('-364 days'),
            default => $today->modify('-29 days'),
        };
        if ($from > $today) {
            $from = $today;
        }

        $days = 0;
        for ($cursor = $from; $cursor < $to; $cursor = $cursor->modify('+1 day')) {
            $days++;
        }
        $prevFrom = $from;
        for ($i = 0; $i < $days; $i++) {
            $prevFrom = $prevFrom->modify('-1 day');
        }

        $label = match ($key) {
            '7d' => 'Posledních 7 dní',
            'mesic' => 'Tento měsíc',
            '90d' => 'Posledních 90 dní',
            'rok' => 'Poslední rok',
            default => 'Posledních 30 dní',
        };

        return [
            'key' => $key,
            'label' => $label,
            'compare' => $this->daysPhrase($days),
            'from' => $from,
            'to' => $to,
            'prev_from' => $prevFrom,
            'start_day' => $from->format('Y-m-d'),
            'end_day' => $to->modify('-1 day')->format('Y-m-d'),
            'tabs' => [
                '7d' => '7 dní',
                '30d' => '30 dní',
                'mesic' => 'Měsíc',
                '90d' => '90 dní',
                'rok' => 'Rok',
            ],
        ];
    }

    /**
     * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable,prev_from:\DateTimeImmutable} $period
     * @return array<string, mixed>
     */
    private function money(array $period): array
    {
        $columns = (new PaymentService($this->db))->paymentColumnSet();
        $feeExpr = isset($columns['fee_amount']) ? 'p.fee_amount' : '0';
        $chargeExpr = isset($columns['charged_amount']) ? 'p.charged_amount' : '0';

        $rows = $this->db->fetchAll(
            'SELECT p.amount, ' . $feeExpr . ' AS fee_amount, ' . $chargeExpr . ' AS charged_amount, p.paid_at,
                    p.reservation_id, p.membership_id,
                    mp.slug AS plan_slug, mp.name AS plan_name, mp.type AS plan_type, mp.sort_order AS plan_sort
             FROM payments p
             LEFT JOIN memberships m ON m.id = p.membership_id
             LEFT JOIN membership_plans mp ON mp.id = m.plan_id
             WHERE ' . PaymentService::revenueSql('p') . ' AND p.paid_at >= :a AND p.paid_at < :b',
            [
                'a' => Clock::toUtc($period['prev_from'])->format('Y-m-d H:i:s'),
                'b' => Clock::toUtc($period['to'])->format('Y-m-d H:i:s'),
            ]
        );

        $days = [];
        for ($cursor = $period['from']; $cursor < $period['to']; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = [
                'date' => $key,
                'label' => $cursor->format('j.n.'),
                'amount' => 0.0,
                'count' => 0,
                'reservations' => 0.0,
                'memberships' => 0.0,
                'other' => 0.0,
                'parts' => [],
            ];
        }

        $net = 0.0;
        $previous = 0.0;
        $fees = 0.0;
        $charged = 0.0;
        $count = 0;
        $catalog = [];
        $breakdown = ['reservations' => 0.0, 'memberships' => 0.0, 'other' => 0.0];

        foreach ($rows as $row) {
            $local = Clock::toLocal((string) $row['paid_at']);
            $amount = (float) ($row['amount'] ?? 0);
            $inCurrent = $local >= $period['from'] && $local < $period['to'];
            $inPrevious = $local >= $period['prev_from'] && $local < $period['from'];
            if ($inPrevious) {
                $previous += $amount;
            }
            if (!$inCurrent) {
                continue;
            }

            $net += $amount;
            $fees += (float) ($row['fee_amount'] ?? 0);
            $charged += (float) ($row['charged_amount'] ?? 0);
            $count++;

            if (!empty($row['reservation_id'])) {
                $bucket = 'reservations';
                $segment = ['key' => 'entry', 'label' => 'Vstupné', 'kind' => 'entry', 'sort' => 0];
            } elseif (!empty($row['membership_id'])) {
                $bucket = 'memberships';
                $slug = strtolower(trim((string) ($row['plan_slug'] ?? '')));
                $safe = preg_replace('/[^a-z0-9_-]/', '', $slug) ?? '';
                $label = trim((string) ($row['plan_name'] ?? ''));
                $segment = [
                    'key' => $safe !== '' ? 'plan:' . $safe : 'plan:membership',
                    'label' => $label !== '' ? $label : 'Členství',
                    'kind' => (string) ($row['plan_type'] ?? 'membership'),
                    'sort' => 100 + (int) ($row['plan_sort'] ?? 0),
                ];
            } else {
                $bucket = 'other';
                $segment = ['key' => 'other', 'label' => 'Ostatní', 'kind' => 'other', 'sort' => 900];
            }

            $breakdown[$bucket] += $amount;
            $segmentKey = $segment['key'];
            if (!isset($catalog[$segmentKey])) {
                $catalog[$segmentKey] = $segment + ['amount' => 0.0];
            }
            $catalog[$segmentKey]['amount'] += $amount;

            $dayKey = $local->format('Y-m-d');
            if (isset($days[$dayKey])) {
                $days[$dayKey]['amount'] += $amount;
                $days[$dayKey]['count']++;
                $days[$dayKey][$bucket] += $amount;
                $days[$dayKey]['parts'][$segmentKey] = ($days[$dayKey]['parts'][$segmentKey] ?? 0) + $amount;
            }
        }

        uasort($catalog, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['label'], $b['label']));
        $used = [];
        $segments = [];
        foreach ($catalog as $item) {
            if ($item['amount'] <= 0) {
                continue;
            }
            $segments[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'color' => $this->segmentColor((string) $item['kind'], $used),
                'amount' => $item['amount'],
            ];
        }

        $gross = $charged > 0 ? $charged : $net + $fees;
        $feePct = $gross > 0 ? ($fees / $gross) * 100 : null;

        $chartDays = [];
        foreach ($days as $day) {
            $day['parts'] = $day['parts'] === [] ? new \stdClass() : $day['parts'];
            $chartDays[] = $day;
        }

        return [
            'net' => $net,
            'previous' => $previous,
            'fees' => $fees,
            'gross' => $gross,
            'fee_pct' => $feePct,
            'count' => $count,
            'average' => $count > 0 ? $net / $count : 0.0,
            'chart' => [
                'days' => $chartDays,
                'breakdown' => $breakdown,
                'segments' => $this->withShares($segments, $net),
                'total' => $net,
                'count' => $count,
            ],
        ];
    }

    /**
     * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable,prev_from:\DateTimeImmutable} $period
     * @return array<string, mixed>
     */
    private function flow(array $period): array
    {
        $padFrom = $period['prev_from']->modify('-2 days');
        $rows = $this->db->fetchAll(
            "SELECT room_id, user_id, status, starts_at, ends_at, guest_count
             FROM reservations
             WHERE starts_at < :to AND ends_at > :from",
            [
                'from' => Clock::toUtc($padFrom)->format('Y-m-d H:i:s'),
                'to' => Clock::toUtc($period['to'])->format('Y-m-d H:i:s'),
            ]
        );

        $now = Clock::nowLocal();
        $slots = [];
        $visits = 0;
        $visitsPrev = 0;
        $people = 0;
        $cancel = 0;
        $noshow = 0;
        $scheduled = 0;
        $userIds = [];
        $uniquePrev = [];
        $visitByDay = [];
        $visitPoints = [];
        for ($cursor = $period['from']; $cursor < $period['to']; $cursor = $cursor->modify('+1 day')) {
            $visitByDay[$cursor->format('Y-m-d')] = 0;
        }

        foreach ($rows as $row) {
            $start = Clock::toLocal((string) $row['starts_at']);
            $end = Clock::toLocal((string) $row['ends_at']);
            if ($end <= $start) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $slots[] = [
                'room' => (int) ($row['room_id'] ?? 0),
                'start' => $start,
                'end' => $end,
                'status' => $status,
            ];

            $inCurrent = $start >= $period['from'] && $start < $period['to'];
            $inPrevious = $start >= $period['prev_from'] && $start < $period['from'];
            $happened = in_array($status, ['confirmed', 'completed'], true) && $start <= $now;
            if ($happened && $inCurrent) {
                $visits++;
                $people += max(1, (int) ($row['guest_count'] ?? 1));
                $dayKey = $start->format('Y-m-d');
                if (isset($visitByDay[$dayKey])) {
                    $visitByDay[$dayKey]++;
                }
                $visitPoints[] = [(int) $start->format('N'), (int) $start->format('G')];
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $userIds[$userId] = $userId;
                }
            } elseif ($happened && $inPrevious) {
                $visitsPrev++;
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $uniquePrev[$userId] = $userId;
                }
            }

            if ($inCurrent && in_array($status, ['confirmed', 'completed', 'cancelled', 'no_show'], true)) {
                $scheduled++;
                if ($status === 'cancelled') {
                    $cancel++;
                } elseif ($status === 'no_show') {
                    $noshow++;
                }
            }
        }

        $entryRows = $this->db->fetchAll(
            "SELECT user_id, created_at
             FROM access_logs
             WHERE authorization_result = 'granted' AND created_at >= :a AND created_at < :b",
            [
                'a' => Clock::toUtc($period['prev_from'])->format('Y-m-d H:i:s'),
                'b' => Clock::toUtc($period['to'])->format('Y-m-d H:i:s'),
            ]
        );

        $entries = 0;
        $entriesPrev = 0;
        $entryByDay = $visitByDay;
        foreach (array_keys($entryByDay) as $dayKey) {
            $entryByDay[$dayKey] = 0;
        }
        $entryPoints = [];
        foreach ($entryRows as $row) {
            $local = Clock::toLocal((string) $row['created_at']);
            if ($local >= $period['from'] && $local < $period['to']) {
                $entries++;
                $dayKey = $local->format('Y-m-d');
                if (isset($entryByDay[$dayKey])) {
                    $entryByDay[$dayKey]++;
                }
                $entryPoints[] = [(int) $local->format('N'), (int) $local->format('G')];
            } elseif ($local >= $period['prev_from'] && $local < $period['from']) {
                $entriesPrev++;
            }
        }

        $useEntries = $visits === 0 && $entries > 0;
        $seriesKey = $useEntries ? 'odemceni' : 'navstevy';
        $seriesLabel = $useEntries ? 'Odemčení' : 'Návštěvy';
        $seriesColor = $useEntries ? '#6ec8ff' : '#c6f21a';
        $byDay = $useEntries ? $entryByDay : $visitByDay;
        $points = $useEntries ? $entryPoints : $visitPoints;
        $seriesTotal = array_sum($byDay);

        $chartDays = [];
        foreach ($byDay as $dayKey => $value) {
            $chartDays[] = [
                'date' => $dayKey,
                'label' => Clock::parseLocal($dayKey . ' 00:00:00')->format('j.n.'),
                'amount' => $value,
                'count' => $value,
                'parts' => [$seriesKey => $value],
            ];
        }

        $newCustomers = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at >= :a AND created_at < :b",
            [
                'a' => Clock::toUtc($period['from'])->format('Y-m-d H:i:s'),
                'b' => Clock::toUtc($period['to'])->format('Y-m-d H:i:s'),
            ]
        );

        return [
            'visits' => $visits,
            'visits_prev' => $visitsPrev,
            'people' => $people,
            'entries' => $entries,
            'entries_prev' => $entriesPrev,
            'unique' => count($userIds),
            'unique_prev' => count($uniquePrev),
            'returning' => $this->returningCount(array_values($userIds), Clock::toUtc($period['from'])->format('Y-m-d H:i:s')),
            'new_customers' => $newCustomers,
            'cancel' => $cancel,
            'noshow' => $noshow,
            'scheduled' => $scheduled,
            'slots' => $slots,
            'chart' => [
                'days' => $chartDays,
                'segments' => [[
                    'key' => $seriesKey,
                    'label' => $seriesLabel,
                    'color' => $seriesColor,
                    'amount' => $seriesTotal,
                ]],
                'total' => $seriesTotal,
            ],
            'chart_label' => $seriesLabel,
            'chart_note' => $useEntries
                ? 'V termínech nic není, graf ukazuje úspěšná odemčení zámku.'
                : 'Potvrzený termín, který už začal.',
            'weekdays' => $this->weekdays($points),
            'heat' => $this->heatmap($points, $useEntries),
        ];
    }

    /**
     * @param list<int> $userIds
     */
    private function returningCount(array $userIds, string $beforeUtc): int
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return 0;
        }
        $params = ['before' => $beforeUtc];
        $holders = [];
        foreach ($userIds as $i => $id) {
            $name = 'u' . $i;
            $holders[] = ':' . $name;
            $params[$name] = $id;
        }

        return (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT user_id) FROM reservations
             WHERE user_id IN (" . implode(',', $holders) . ")
               AND status IN ('confirmed', 'completed')
               AND starts_at < :before",
            $params
        );
    }

    /**
     * @param list<array{0:int,1:int}> $points
     * @return list<array{short:string,count:int,pct:int}>
     */
    private function weekdays(array $points): array
    {
        $counts = array_fill(1, 7, 0);
        foreach ($points as [$day]) {
            if (isset($counts[$day])) {
                $counts[$day]++;
            }
        }
        $max = max($counts);
        $rows = [];
        foreach ($counts as $day => $count) {
            $rows[] = [
                'short' => self::DAY_SHORT[$day],
                'count' => $count,
                'pct' => $max > 0 ? (int) round($count / $max * 100) : 0,
            ];
        }
        return $rows;
    }

    /**
     * @param list<array{0:int,1:int}> $points
     * @return array{rows:list<array<string,mixed>>,hours:list<int>,caption:?string,source:string,empty:bool}
     */
    private function heatmap(array $points, bool $entries): array
    {
        $grid = [];
        for ($day = 1; $day <= 7; $day++) {
            $grid[$day] = array_fill(0, 24, 0);
        }
        foreach ($points as [$day, $hour]) {
            if (isset($grid[$day][$hour])) {
                $grid[$day][$hour]++;
            }
        }

        $peakDay = 1;
        $peakHour = 0;
        $peak = 0;
        $minHour = 23;
        $any = false;
        for ($day = 1; $day <= 7; $day++) {
            for ($hour = 0; $hour <= 23; $hour++) {
                $count = $grid[$day][$hour];
                if ($count > $peak) {
                    $peak = $count;
                    $peakDay = $day;
                    $peakHour = $hour;
                }
                if ($count > 0) {
                    $any = true;
                    if ($hour < $minHour) {
                        $minHour = $hour;
                    }
                }
            }
        }

        $fromHour = $any ? min(6, $minHour) : 6;
        $toHour = 23;
        $hours = range($fromHour, $toHour);
        $rows = [];
        foreach ($grid as $day => $hourCounts) {
            $cells = [];
            foreach ($hours as $hour) {
                $count = $hourCounts[$hour];
                $cells[] = [
                    'hour' => $hour,
                    'count' => $count,
                    'level' => $peak > 0 ? round($count / $peak, 2) : 0,
                ];
            }
            $rows[] = [
                'name' => self::DAY_IN[$day],
                'short' => self::DAY_SHORT[$day],
                'cells' => $cells,
            ];
        }

        $caption = null;
        if ($any) {
            $where = self::DAY_IN[$peakDay] ?? '';
            $caption = $entries
                ? 'Nejvíc odemčení je ' . $where . ' mezi ' . $peakHour . ' a ' . ($peakHour + 1) . ' h.'
                : 'Nejvíc termínů začíná ' . $where . ' mezi ' . $peakHour . ' a ' . ($peakHour + 1) . ' h.';
        }

        return [
            'rows' => $rows,
            'hours' => $hours,
            'caption' => $caption,
            'source' => $entries ? 'Podle odemčení zámku' : 'Podle začátku termínů',
            'empty' => !$any,
        ];
    }

    /**
     * @param list<array{room:int,start:\DateTimeImmutable,end:\DateTimeImmutable,status:string}> $slots
     * @return array{percent:?int,booked:int,open:int}
     */
    private function occupancy(\DateTimeImmutable $from, \DateTimeImmutable $to, array $slots): array
    {
        $rooms = $this->db->fetchAll('SELECT id FROM rooms WHERE is_active = 1 ORDER BY id ASC');
        if ($rooms === []) {
            return ['percent' => null, 'booked' => 0, 'open' => 0];
        }

        $hours = [];
        foreach ($this->db->fetchAll(
            'SELECT h.* FROM opening_hours h INNER JOIN rooms r ON r.id = h.room_id WHERE r.is_active = 1'
        ) as $row) {
            $hours[(int) $row['room_id']][(int) $row['weekday']] = $row;
        }

        $exceptions = [];
        foreach ($this->db->fetchAll(
            'SELECT e.* FROM opening_hour_exceptions e
             INNER JOIN rooms r ON r.id = e.room_id
             WHERE r.is_active = 1 AND e.exception_date >= :a AND e.exception_date < :b',
            ['a' => $from->format('Y-m-d'), 'b' => $to->format('Y-m-d')]
        ) as $row) {
            $exceptions[(int) $row['room_id'] . '|' . (string) $row['exception_date']] = $row;
        }

        $active = [];
        foreach ($slots as $slot) {
            if (in_array($slot['status'], ['confirmed', 'completed'], true)) {
                $active[] = $slot;
            }
        }

        $booked = 0;
        $openMinutes = 0;
        for ($cursor = $from; $cursor < $to; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $weekday = (int) $cursor->format('N');
            foreach ($rooms as $room) {
                $roomId = (int) $room['id'];
                $window = $this->openWindow($hours, $exceptions, $roomId, $date, $weekday, $cursor);
                if ($window === null) {
                    continue;
                }
                [$openAt, $closeAt] = $window;
                $span = (int) round(($closeAt->getTimestamp() - $openAt->getTimestamp()) / 60);
                if ($span <= 0) {
                    continue;
                }
                $openMinutes += $span;
                $used = 0;
                foreach ($active as $slot) {
                    if ($slot['room'] !== $roomId) {
                        continue;
                    }
                    $used += $this->overlapMinutes($slot['start'], $slot['end'], $openAt, $closeAt);
                }
                $booked += min($used, $span);
            }
        }

        return [
            'percent' => $openMinutes > 0 ? (int) round($booked / $openMinutes * 100) : null,
            'booked' => $booked,
            'open' => $openMinutes,
            'rooms' => count($rooms),
        ];
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $hours
     * @param array<string, array<string, mixed>> $exceptions
     * @return ?array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function openWindow(array $hours, array $exceptions, int $roomId, string $date, int $weekday, \DateTimeImmutable $day): ?array
    {
        $exception = $exceptions[$roomId . '|' . $date] ?? null;
        if ($exception) {
            if ((int) ($exception['is_closed'] ?? 0) === 1) {
                return null;
            }
            $opens = (string) ($exception['opens_at'] ?? '06:00:00');
            $closes = (string) ($exception['closes_at'] ?? '22:00:00');
        } else {
            $row = $hours[$roomId][$weekday] ?? null;
            if (!$row || (int) ($row['is_closed'] ?? 0) === 1) {
                return null;
            }
            $opens = (string) $row['opens_at'];
            $closes = (string) $row['closes_at'];
        }

        $openAt = Clock::parseLocal($day->format('Y-m-d') . ' ' . $this->normalizeTime($opens));
        $closeAt = Clock::parseLocal($day->format('Y-m-d') . ' ' . $this->normalizeTime($closes));
        if ($closeAt <= $openAt) {
            $closeAt = $closeAt->modify('+1 day');
        }
        return [$openAt, $closeAt];
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time . ':00';
        }
        return $time !== '' ? $time : '00:00:00';
    }

    private function overlapMinutes(\DateTimeImmutable $a1, \DateTimeImmutable $a2, \DateTimeImmutable $b1, \DateTimeImmutable $b2): int
    {
        $start = $a1 > $b1 ? $a1 : $b1;
        $end = $a2 < $b2 ? $a2 : $b2;
        if ($end <= $start) {
            return 0;
        }
        return (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    /**
     * @param array{key:string,label:string,compare:string,start_day:string,end_day:string,tabs:array<string,string>} $period
     * @param array<string, mixed> $money
     * @param array<string, mixed> $flow
     * @param array{percent:?int,booked:int,open:int,rooms:int} $occupancy
     * @param array{percent:?int,booked:int,open:int,rooms:int} $occupancyPrev
     * @return array<string, mixed>
     */
    private function present(array $period, array $money, array $flow, array $occupancy, array $occupancyPrev): array
    {
        $netChange = $this->change((float) $money['net'], (float) $money['previous']);
        $visitChange = $this->change((float) $flow['visits'], (float) $flow['visits_prev']);
        $entryChange = $this->change((float) $flow['entries'], (float) $flow['entries_prev']);
        $uniqueChange = $this->change((float) $flow['unique'], (float) $flow['unique_prev']);
        $perVisit = (int) $flow['visits'] > 0 ? ((float) $money['net']) / (int) $flow['visits'] : 0.0;
        $returningPct = (int) $flow['unique'] > 0 ? ((int) $flow['returning'] / (int) $flow['unique']) * 100 : null;

        $occTone = 'flat';
        if ($occupancy['percent'] !== null && $occupancyPrev['percent'] !== null) {
            if ($occupancy['percent'] > $occupancyPrev['percent']) {
                $occTone = 'up';
            } elseif ($occupancy['percent'] < $occupancyPrev['percent']) {
                $occTone = 'down';
            }
        }

        $peopleHint = '';
        if ((int) $flow['people'] > 0 && (int) $flow['people'] !== (int) $flow['visits']) {
            $peopleHint = $this->peopleLabel((int) $flow['people']);
        }

        $maxMoney = max((float) $money['net'], (float) $money['previous']);
        $bar = static function (float $value, float $max): int {
            if ($value <= 0 || $max <= 0) {
                return 0;
            }
            return max(4, (int) round($value / $max * 100));
        };

        return [
            'key' => $period['key'],
            'label' => $period['label'],
            'compare' => $period['compare'],
            'start_day' => $period['start_day'],
            'end_day' => $period['end_day'],
            'tabs' => $period['tabs'],
            'verdict' => $this->verdict($period, $money, $netChange),
            'bars' => [
                'current' => money_format_czk($money['net']),
                'previous' => money_format_czk($money['previous']),
                'current_width' => $bar((float) $money['net'], $maxMoney),
                'previous_width' => $bar((float) $money['previous'], $maxMoney),
            ],
            'kpis' => [
                [
                    'label' => 'Návštěvy',
                    'value' => $this->num((int) $flow['visits']),
                    'delta' => $visitChange['label'],
                    'tone' => $visitChange['tone'],
                    'hint' => $peopleHint,
                ],
                [
                    'label' => 'Odemčení',
                    'value' => $this->num((int) $flow['entries']),
                    'delta' => $entryChange['label'],
                    'tone' => $entryChange['tone'],
                    'hint' => 'úspěšný vstup přes zámek',
                ],
                [
                    'label' => 'Obsazenost',
                    'value' => $occupancy['percent'] === null ? '—' : $occupancy['percent'] . ' %',
                    'delta' => $occupancyPrev['percent'] === null ? '' : 'minule ' . $occupancyPrev['percent'] . ' %',
                    'tone' => $occTone,
                    'hint' => $occupancy['open'] > 0 ? $this->hoursLabel((int) $occupancy['booked']) . ' z ' . $this->hoursLabel((int) $occupancy['open']) : 'bez otevírací doby',
                ],
                [
                    'label' => 'Tržby',
                    'value' => money_format_czk($money['net']),
                    'delta' => $netChange['label'],
                    'tone' => $netChange['tone'],
                    'hint' => (int) $flow['visits'] > 0 ? $this->num((int) round($perVisit)) . ' Kč / návštěva' : '',
                    'href' => true,
                ],
                [
                    'label' => 'Poplatky',
                    'value' => money_format_czk($money['fees']),
                    'delta' => (float) $money['fees'] > 0.5 && $money['fee_pct'] !== null ? $this->shareLabel((float) $money['fee_pct']) . ' z plateb' : 'brána nic nestrhla',
                    'tone' => 'flat',
                    'hint' => '',
                ],
                [
                    'label' => 'Unikátní',
                    'value' => $this->num((int) $flow['unique']),
                    'delta' => $uniqueChange['label'],
                    'tone' => $uniqueChange['tone'],
                    'hint' => $returningPct === null ? '' : $this->shareLabel($returningPct) . ' se vrací',
                ],
            ],
            'visit_chart' => $flow['chart'],
            'chart_label' => $flow['chart_label'],
            'chart_note' => $flow['chart_note'],
            'money_chart' => $money['chart'],
            'per_payment' => money_format_czk($money['average']),
            'payment_count' => (int) $money['count'],
            'weekdays' => $flow['weekdays'],
            'week_empty' => array_sum(array_column($flow['weekdays'], 'count')) === 0,
            'heat' => $flow['heat'],
            'occupancy' => [
                'percent' => $occupancy['percent'],
                'booked' => $this->hoursLabel((int) $occupancy['booked']),
                'open' => $this->hoursLabel((int) $occupancy['open']),
                'has_hours' => (int) $occupancy['open'] > 0,
                'prev' => $occupancyPrev['percent'],
                'tone' => $occTone,
                'rooms' => (int) ($occupancy['rooms'] ?? 1),
            ],
            'facts' => [
                [
                    'label' => 'Noví zákazníci',
                    'value' => $this->num((int) $flow['new_customers']),
                    'hint' => 'nové účty v období',
                ],
                [
                    'label' => 'Vracející se',
                    'value' => $returningPct === null ? '—' : $this->shareLabel($returningPct),
                    'hint' => (int) $flow['unique'] > 0 ? $this->num((int) $flow['returning']) . ' z ' . $this->num((int) $flow['unique']) . ' návštěvníků' : 'nikdo nepřišel',
                ],
                [
                    'label' => 'Zrušené',
                    'value' => (int) $flow['scheduled'] > 0 ? $this->shareLabel(((int) $flow['cancel'] / (int) $flow['scheduled']) * 100) : '—',
                    'hint' => $this->termsHint((int) $flow['scheduled']),
                ],
                [
                    'label' => 'Nedostavení',
                    'value' => (int) $flow['scheduled'] > 0 ? $this->shareLabel(((int) $flow['noshow'] / (int) $flow['scheduled']) * 100) : '—',
                    'hint' => $this->termsHint((int) $flow['scheduled']),
                ],
            ],
        ];
    }

    /**
     * @param array{compare:string} $period
     * @param array<string, mixed> $money
     * @param array{diff:float,pct:?float,tone:string,label:string} $change
     * @return array{tone:string,title:string,text:string}
     */
    private function verdict(array $period, array $money, array $change): array
    {
        $current = (float) $money['net'];
        $previous = (float) $money['previous'];
        $fee = (float) $money['fees'];
        $window = $period['compare'];
        $name = $fee > 0.5 ? 'Čistý příjem' : 'Tržby';

        if ($current < 0.5 && $previous < 0.5) {
            $tone = 'empty';
            $title = 'Zatím bez tržeb';
            $text = 'V tomhle období ani za ' . $window . ' nepřišla žádná platba.';
        } elseif ($change['tone'] === 'up') {
            $tone = 'up';
            $title = 'Plus ' . money_format_czk($change['diff']);
            $text = $name . ' je o ' . money_format_czk($change['diff']) . ' výš než za ' . $window;
            $text .= $change['pct'] === null ? '. Předtím v tom okně nic nepřišlo.' : ' (' . $change['label'] . ').';
        } elseif ($change['tone'] === 'down') {
            $tone = 'down';
            $title = 'Minus ' . money_format_czk(abs($change['diff']));
            $text = $name . ' je o ' . money_format_czk(abs($change['diff'])) . ' níž než za ' . $window;
            $text .= $change['pct'] === null ? '.' : ' (' . $change['label'] . ').';
        } else {
            $tone = 'flat';
            $title = 'Stejně jako minule';
            $text = $name . ' ' . money_format_czk($current) . ' je na stejné úrovni jako za ' . $window . '.';
        }

        if ($fee > 0.5 && $money['fee_pct'] !== null) {
            $text .= ' Poplatky brány jsou ' . money_format_czk($fee) . ' (' . $this->shareLabel((float) $money['fee_pct']) . ' z plateb).';
        }

        return ['tone' => $tone, 'title' => $title, 'text' => $text];
    }

    /**
     * @return array{diff:float,pct:?float,tone:string,label:string}
     */
    private function change(float $current, float $previous): array
    {
        $diff = $current - $previous;
        $pct = abs($previous) > 0.5 ? ($diff / abs($previous)) * 100 : null;
        $tone = 'flat';
        if ($diff > 0.5) {
            $tone = 'up';
        } elseif ($diff < -0.5) {
            $tone = 'down';
        }

        if ($pct === null) {
            $label = $tone === 'up' ? 'nové' : '0 %';
        } else {
            $rounded = abs($pct) >= 10 ? round($pct) : round($pct, 1);
            if (abs($rounded) < 0.05) {
                $label = '0 %';
                $tone = 'flat';
            } else {
                $sign = $rounded > 0 ? '+' : '−';
                $abs = abs($rounded);
                $num = abs($abs - round($abs)) < 0.05
                    ? (string) (int) round($abs)
                    : number_format($abs, 1, ',', ' ');
                $label = $sign . $num . ' %';
            }
        }

        return ['diff' => $diff, 'pct' => $pct, 'tone' => $tone, 'label' => $label];
    }

    private function shareLabel(float $pct): string
    {
        $rounded = abs($pct) >= 10 ? round($pct) : round($pct, 1);
        if (abs($rounded - round($rounded)) < 0.05) {
            return (int) round(abs($rounded)) . ' %';
        }
        return number_format(abs($rounded), 1, ',', ' ') . ' %';
    }

    private function num(int|float $value): string
    {
        return number_format((float) $value, 0, ',', ' ');
    }

    private function hoursLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours === 0) {
            return $rest . ' min';
        }
        if ($rest === 0) {
            return $hours . ' h';
        }
        return $hours . ' h ' . $rest . ' min';
    }

    private function peopleLabel(int $count): string
    {
        $mod = $count % 10;
        $mod100 = $count % 100;
        if ($count === 1) {
            return '1 osoba';
        }
        if ($mod >= 2 && $mod <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $this->num($count) . ' osoby';
        }
        return $this->num($count) . ' osob';
    }

    private function termsHint(int $count): string
    {
        if ($count === 0) {
            return 'žádné termíny';
        }
        if ($count === 1) {
            return 'z 1 termínu';
        }
        return 'z ' . $this->num($count) . ' termínů';
    }

    private function daysPhrase(int $days): string
    {
        if ($days === 1) {
            return 'předchozí den';
        }
        $mod = $days % 10;
        $mod100 = $days % 100;
        if ($mod >= 2 && $mod <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'předchozí ' . $days . ' dny';
        }
        return 'předchozích ' . $days . ' dní';
    }

    /**
     * @param list<array{key:string,label:string,color:string,amount:float}> $segments
     * @return list<array{key:string,label:string,color:string,amount:float,pct:int}>
     */
    private function withShares(array $segments, float $total): array
    {
        if ($total <= 0 || $segments === []) {
            return $segments;
        }
        $left = 100;
        $last = count($segments) - 1;
        foreach ($segments as $i => &$segment) {
            if ($i === $last) {
                $segment['pct'] = max(0, $left);
                continue;
            }
            $pct = (int) round(((float) $segment['amount'] / $total) * 100);
            if ($pct > $left) {
                $pct = $left;
            }
            $segment['pct'] = $pct;
            $left -= $pct;
        }
        unset($segment);
        return $segments;
    }

    /**
     * @param array<string, true> $used
     */
    private function segmentColor(string $kind, array &$used): string
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
}
