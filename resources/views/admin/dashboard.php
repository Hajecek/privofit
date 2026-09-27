<?php
$stats = is_array($stats ?? null) ? $stats : [];
$chart = is_array($chart ?? null) ? $chart : ['days' => [], 'breakdown' => [], 'total' => 0, 'count' => 0];
$current = is_array($stats['current'] ?? null) ? $stats['current'] : [];
$door = is_array($stats['door'] ?? null) ? $stats['door'] : [];
$notices = is_array($stats['notices'] ?? null) ? $stats['notices'] : [];
$next = is_array($stats['next'] ?? null) ? $stats['next'] : null;
$occupied = !empty($current['occupied']);
$reservation = is_array($current['reservation'] ?? null) ? $current['reservation'] : null;
$online = !empty($door['online']);
$configured = !empty($door['configured']);
$testMode = !empty($door['test_mode']);
$doorLock = strtolower((string) ($door['lock_state'] ?? ''));
$doorSensor = strtolower((string) ($door['door_state'] ?? ''));
$doorOpen = $configured && (
    in_array($doorLock, ['unlocked', 'unlatched', 'unlocking', 'unlatching', 'unlocked_lock_n_go', 'odkleceno'], true)
    || in_array($doorSensor, ['opened', 'open'], true)
);
$doorBattery = isset($door['battery_percent']) && $door['battery_percent'] !== null && $door['battery_percent'] !== ''
    ? max(0, min(100, (int) $door['battery_percent']))
    : null;
$doorBatteryLow = !empty($door['battery_critical']) || ($doorBattery !== null && $doorBattery <= 15);
$nowLocal = \App\Support\Clock::nowLocal();
$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}';
$segments = is_array($chart['segments'] ?? null) ? $chart['segments'] : [];
$dayNames = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];
$todayLabel = ($dayNames[(int) $nowLocal->format('N')] ?? '') . ' ' . $nowLocal->format('j. n. Y');

$blockEnd = static function (string $endsAt, mixed $buffer): \DateTimeImmutable {
    return \App\Support\Clock::toLocal($endsAt)->modify('+' . max(0, (int) $buffer) . ' minutes');
};

$guestName = '';
$slotLabel = '';
if ($reservation) {
    $guestName = trim((string) (($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? '')));
    if (!empty($reservation['starts_at']) && !empty($reservation['ends_at'])) {
        $start = \App\Support\Clock::toLocal((string) $reservation['starts_at']);
        $end = $blockEnd((string) $reservation['ends_at'], $reservation['buffer_minutes'] ?? 15);
        $endClock = $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : $end->format('j. n. H:i');
        $slotLabel = $start->format('H:i') . '–' . $endClock;
    }
}

$nextLabel = '';
if ($next && !empty($next['starts_at'])) {
    $ns = \App\Support\Clock::toLocal((string) $next['starts_at']);
    $nextName = trim((string) (($next['first_name'] ?? '') . ' ' . ($next['last_name'] ?? '')));
    $nextWhen = $ns->format('Y-m-d') === $nowLocal->format('Y-m-d')
        ? $ns->format('H:i')
        : ($dayNames[(int) $ns->format('N')] ?? '') . ' ' . $ns->format('j. n.') . ' ' . $ns->format('H:i');
    $nextLabel = $nextWhen . ($nextName !== '' ? ' · ' . $nextName : '');
}

$statusText = $occupied ? 'Obsazeno' : 'Volno';
$statusClass = $occupied ? 'is-busy' : 'is-free';

$notifyCount = count($notices) + (($configured && !$online) ? 1 : 0) + ($doorBatteryLow ? 1 : 0);
$liveRev = (string) ($liveRev ?? '');
?>
<div class="adash" data-dash-root data-dash-url="<?= e(url('/user/sprava/live')) ?>" data-dash-rev="<?= e($liveRev) ?>">
    <header class="adash-hero">
        <div class="adash-hero-copy">
            <p class="eyebrow">SPRÁVA</p>
            <h1>Přehled</h1>
            <p class="muted" data-dash-clock><?= e($todayLabel) ?> · <?= e($nowLocal->format('H:i')) ?></p>
        </div>
        <div class="adash-hero-tools">
            <a class="adash-chip<?= $doorBatteryLow ? ' is-low' : '' ?>" href="<?= e(url('/user/sprava/vstup')) ?>" data-hero-battery>
                <span class="door-bat<?= $doorBatteryLow ? ' is-low' : '' ?>" data-dash-bat aria-hidden="true"><span class="door-bat-fill" data-dash-fill style="width: <?= $doorBattery ?? 0 ?>%"></span></span>
                <span class="adash-chip-copy">
                    <strong data-dash-battery><?= $doorBattery === null ? '—' : e((string) $doorBattery) . '%' ?></strong>
                    <span>Baterie</span>
                </span>
            </a>
            <a class="adash-chip<?= !$configured ? '' : ($doorOpen ? ' is-open' : ' is-closed') ?>" href="<?= e(url('/user/sprava/vstup')) ?>" data-hero-door>
                <span class="adash-chip-dot" aria-hidden="true"></span>
                <span class="adash-chip-copy">
                    <strong data-hero-door-state><?= !$configured ? 'Neznámý' : ($doorOpen ? 'Otevřeno' : 'Zavřeno') ?></strong>
                    <span>Zámek</span>
                </span>
            </a>
            <div class="adash-hero-status">
            <div class="adash-live <?= e($statusClass) ?>" data-dash-occupancy>
                <span class="adash-live-pulse" aria-hidden="true"></span>
                <div>
                    <strong data-dash-status><?= e($statusText) ?></strong>
                    <span data-dash-detail><?= $occupied ? e($guestName !== '' ? $guestName . ($slotLabel !== '' ? ' · ' . $slotLabel : '') : 'Aktivní rezervace') : ($nextLabel !== '' ? 'Další: ' . e($nextLabel) : 'Žádný další termín') ?></span>
                </div>
            </div>
            <div class="adash-notify-wrap">
                <button type="button" class="adash-bell" data-notify-open aria-expanded="false" aria-controls="adash-notify" aria-label="Upozornění">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                    <span class="adash-bell-count" data-notify-count <?= $notifyCount === 0 ? 'hidden' : '' ?>><?= (int) $notifyCount ?></span>
                </button>
                <div class="adash-notify" id="adash-notify" data-notify hidden>
                    <div class="adash-notify-head">
                        <h2>Upozornění</h2>
                        <span class="muted">Posledních 7 dní</span>
                    </div>
                    <ul class="adash-notes">
                        <li class="adash-note is-bad is-unread" data-notify-id="door-offline" data-notify-offline <?= $configured && !$online ? '' : 'hidden' ?>>
                            <button type="button" class="adash-note-seen" data-notify-seen aria-pressed="false" aria-label="Označit jako viděné"></button>
                            <a class="adash-note-link" href="<?= e(url('/user/sprava/vstup')) ?>">
                                <span class="adash-note-mark is-door" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 8.5c2.8-2.2 6.2-3.5 10-3.5s7.2 1.3 10 3.5"/><path d="M5 12c2-1.6 4.4-2.4 7-2.4s5 .8 7 2.4"/><path d="M8.5 15.5c1-.7 2.2-1.1 3.5-1.1s2.5.4 3.5 1.1"/><path d="M12 19h.01"/></svg></span>
                                <span class="adash-note-body">
                                    <span class="adash-note-top"><strong>Zámek je offline</strong><span class="adash-note-meta"><span class="adash-note-kind is-door">Dveře</span><time>Teď</time></span></span>
                                    <span class="adash-note-text">Spojení se zámkem teď neodpovídá.</span>
                                </span>
                                <span class="adash-note-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 6l6 6-6 6"/></svg></span>
                            </a>
                        </li>
                        <li class="adash-note is-bad is-unread" data-notify-id="door-battery" data-notify-battery <?= $doorBatteryLow ? '' : 'hidden' ?>>
                            <button type="button" class="adash-note-seen" data-notify-seen aria-pressed="false" aria-label="Označit jako viděné"></button>
                            <a class="adash-note-link" href="<?= e(url('/user/sprava/vstup')) ?>">
                                <span class="adash-note-mark is-door" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="16" height="10" rx="2"/><path d="M21 10v4"/><path d="M7 12h4"/></svg></span>
                                <span class="adash-note-body">
                                    <span class="adash-note-top"><strong>Baterie dochází</strong><span class="adash-note-meta"><span class="adash-note-kind is-door">Dveře</span><time>Teď</time></span></span>
                                    <span class="adash-note-text" data-notify-battery-meta><?= $doorBattery === null ? 'Nabití je kritické.' : 'Zbývá ' . (int) $doorBattery . ' %.' ?></span>
                                </span>
                                <span class="adash-note-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 6l6 6-6 6"/></svg></span>
                            </a>
                        </li>
                        <?php foreach ($notices as $note): ?>
                            <li class="adash-note is-unread<?= ($note['tone'] ?? '') !== '' ? ' is-' . e((string) $note['tone']) : '' ?>" data-notify-dynamic data-notify-id="<?= e((string) ($note['key'] ?? '')) ?>">
                                <button type="button" class="adash-note-seen" data-notify-seen aria-pressed="false" aria-label="Označit jako viděné"></button>
                                <?php $noteHref = (string) ($note['href'] ?? ''); ?>
                                <?php if ($noteHref !== ''): ?><a class="adash-note-link" href="<?= e($noteHref) ?>"><?php else: ?><div class="adash-note-link"><?php endif; ?>
                                    <?php if (!empty($note['avatar'])): ?>
                                        <img class="adash-note-avatar" src="<?= e((string) $note['avatar']) ?>" alt="">
                                    <?php else: ?>
                                        <span class="adash-note-mark" aria-hidden="true"><?= e((string) ($note['initials'] ?? 'P')) ?></span>
                                    <?php endif; ?>
                                    <span class="adash-note-body">
                                        <span class="adash-note-top">
                                            <strong><?= e((string) ($note['title'] ?? '')) ?></strong>
                                            <span class="adash-note-meta">
                                                <span class="adash-note-kind <?= e((string) ($note['kind_class'] ?? '')) ?>"><?= e((string) ($note['kind'] ?? '')) ?></span>
                                                <time><?= e((string) ($note['when'] ?? '')) ?></time>
                                            </span>
                                        </span>
                                        <span class="adash-note-text"><?= e((string) ($note['text'] ?? '')) ?></span>
                                    </span>
                                    <?php if ($noteHref !== ''): ?><span class="adash-note-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 6l6 6-6 6"/></svg></span><?php endif; ?>
                                <?php if ($noteHref !== ''): ?></a><?php else: ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="adash-empty" data-notify-empty <?= $notifyCount === 0 ? '' : 'hidden' ?>>Žádná upozornění.</p>
                </div>
            </div>
            </div>
        </div>
    </header>

    <section
        class="adash-chart card"
        data-dash-chart
        data-chart="<?= e($chartJson) ?>"
        data-day-url="<?= e(url('/user/sprava/trzby?obdobi=den&datum=')) ?>"
        aria-label="Graf příjmu"
    >
        <div class="adash-chart-head">
            <div>
                <p class="eyebrow">PŘÍJEM</p>
                <h2 data-chart-total data-count-to="<?= e(money_format_czk($chart['total'] ?? $stats['revenue'] ?? 0)) ?>">0 Kč</h2>
                <p
                    class="muted"
                    data-chart-hint
                    data-hint-curve="Hladká křivka · klikni na den"
                    data-hint-area="Plochy podle tarifu · klikni na den"
                    data-hint-bar="Sloupce podle tarifu · klikni na den"
                    data-hint-ring="Podíl tarifů"
                >Hladká křivka · klikni na den</p>
            </div>
            <div class="chart-kind" role="tablist" aria-label="Typ grafu">
                <button type="button" class="chart-kind-btn is-on" role="tab" data-chart-kind="curve" aria-selected="true">Křivka</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="area" aria-selected="false">Plocha</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="bar" aria-selected="false">Sloupce</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="ring" aria-selected="false">Kruh</button>
            </div>
        </div>

        <div class="chart-toolbar">
        <ul class="chart-split-legend" data-chart-legend hidden>
            <?php foreach ($segments as $segment): ?>
                <?php
                $segmentColor = (string) ($segment['color'] ?? '');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $segmentColor)) {
                    $segmentColor = '#9aa49c';
                }
                ?>
                <li><i style="background:<?= e($segmentColor) ?>"></i><?= e((string) ($segment['label'] ?? '')) ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <div class="adash-chart-stage">
            <canvas data-dash-line width="800" height="260" aria-label="Vývoj tržeb"></canvas>
            <div class="adash-chart-tip" data-chart-tip hidden></div>
        </div>

    </section>

    <section class="adash-load" aria-label="Dnešní vytížení">
        <?php
        $schedule = is_array($schedule ?? null) ? $schedule : [];
        $schedItems = is_array($schedule['items'] ?? null) ? $schedule['items'] : [];
        $schedWindow = is_array($schedule['window'] ?? null) ? $schedule['window'] : [];
        $schedTicks = is_array($schedWindow['ticks'] ?? null) ? $schedWindow['ticks'] : [];
        $schedNow = isset($schedWindow['now']) && $schedWindow['now'] !== null ? (float) $schedWindow['now'] : null;
        $schedTip = static function (array $row): void {
            $name = (string) ($row['name'] ?? 'Zákazník');
            $username = (string) ($row['username'] ?? '');
            ?>
            <span class="sched-tip">
                <?php if (!empty($row['avatar'])): ?>
                    <img class="sched-tip-avatar" src="<?= e((string) $row['avatar']) ?>" alt="">
                <?php endif; ?>
                <span class="sched-tip-copy">
                    <strong><?= e($name) ?></strong>
                    <?php if ($username !== ''): ?><span class="sched-tip-user">@<?= e($username) ?></span><?php endif; ?>
                    <span class="sched-tip-when"><?= e((string) ($row['time'] ?? '')) ?>–<?= e((string) ($row['end'] ?? '')) ?> · <?= e((string) ($row['badge'] ?? '')) ?></span>
                    <?php if (!empty($row['meta'])): ?><span class="sched-tip-meta"><?= e((string) $row['meta']) ?></span><?php endif; ?>
                </span>
            </span>
            <?php
        };
        $markIndex = 0;
        ?>
        <div data-dash-schedule-body>
        <?php if ($schedItems === []): ?>
            <p class="adash-empty"><?= e((string) ($schedule['empty'] ?? 'Dnes žádné rezervace.')) ?></p>
        <?php else: ?>
            <div class="sched">
                <div class="sched-rail">
                    <div class="sched-rail-labels" aria-hidden="true">
                        <?php foreach ($schedTicks as $tick): ?>
                            <?php if (!is_array($tick)) { continue; } ?>
                            <?php $edge = (string) ($tick['edge'] ?? ''); ?>
                            <span class="<?= $edge === 'start' ? 'is-start' : ($edge === 'end' ? 'is-end' : '') ?>" style="left: <?= e((string) ($tick['left'] ?? 0)) ?>%"><?= e((string) ($tick['label'] ?? '')) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sched-rail-track">
                        <?php if ($schedNow !== null): ?>
                            <i class="sched-rail-elapsed" style="width: <?= e((string) $schedNow) ?>%"></i>
                        <?php endif; ?>
                        <?php foreach ($schedTicks as $tick): ?>
                            <?php if (!is_array($tick)) { continue; } ?>
                            <?php $tickEdge = (string) ($tick['edge'] ?? ''); ?>
                            <i class="sched-tick<?= $tickEdge === 'start' ? ' is-start' : ($tickEdge === 'end' ? ' is-end' : '') ?>" style="left: <?= e((string) ($tick['left'] ?? 0)) ?>%"></i>
                        <?php endforeach; ?>
                        <?php foreach ($schedItems as $mark): ?>
                            <?php if (!is_array($mark)) { continue; } ?>
                            <?php
                            $markState = (string) ($mark['state'] ?? 'next');
                            if (!in_array($markState, ['live', 'next', 'pay', 'past'], true)) {
                                $markState = 'next';
                            }
                            $markTip = (string) ($mark['tip'] ?? 'mid');
                            if (!in_array($markTip, ['start', 'mid', 'end'], true)) {
                                $markTip = 'mid';
                            }
                            $markLabel = trim((string) ($mark['name'] ?? 'Zákazník') . ', ' . (string) ($mark['time'] ?? '') . '–' . (string) ($mark['end'] ?? ''));
                            ?>
                            <?php if (!empty($mark['href'])): ?>
                                <a class="sched-mark is-<?= e($markState) ?> is-tip-<?= e($markTip) ?>" href="<?= e((string) $mark['href']) ?>" style="left: <?= e((string) ($mark['left'] ?? 0)) ?>%; width: <?= e((string) ($mark['width'] ?? 2)) ?>%; --mark: <?= (int) $markIndex ?>" aria-label="<?= e($markLabel) ?>"><?php $schedTip($mark); ?></a>
                            <?php else: ?>
                                <span class="sched-mark is-<?= e($markState) ?> is-tip-<?= e($markTip) ?>" style="left: <?= e((string) ($mark['left'] ?? 0)) ?>%; width: <?= e((string) ($mark['width'] ?? 2)) ?>%; --mark: <?= (int) $markIndex ?>" tabindex="0" aria-label="<?= e($markLabel) ?>"><?php $schedTip($mark); ?></span>
                            <?php endif; ?>
                            <?php $markIndex++; ?>
                        <?php endforeach; ?>
                        <?php if ($schedNow !== null): ?>
                            <i class="sched-now" style="left: <?= e((string) $schedNow) ?>%"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="adash-kpis" aria-label="Klíčové ukazatele">
        <article class="adash-kpi" data-kpi="today_reservations">
            <span>Rezervace dnes</span>
            <strong><?= (int) ($stats['today_reservations'] ?? 0) ?></strong>
        </article>
        <article class="adash-kpi" data-kpi="entries">
            <span>Vstupy dnes</span>
            <strong><?= (int) ($stats['entries'] ?? 0) ?></strong>
        </article>
        <article class="adash-kpi<?= (int) ($stats['failed_access'] ?? 0) > 0 ? ' is-alert' : '' ?>" data-kpi="failed_access">
            <span>Neúspěšné</span>
            <strong><?= (int) ($stats['failed_access'] ?? 0) ?></strong>
        </article>
        <article class="adash-kpi" data-kpi="active_members">
            <span>Aktivní členové</span>
            <strong><?= (int) ($stats['active_members'] ?? 0) ?></strong>
        </article>
        <a class="adash-kpi adash-kpi-link" href="<?= e(url('/user/sprava/zakaznici')) ?>" data-kpi="customers">
            <span>Zákazníci</span>
            <strong><?= (int) ($stats['customers'] ?? 0) ?></strong>
        </a>
        <a class="adash-kpi adash-kpi-link" href="<?= e(url('/user/sprava/zajem')) ?>" data-kpi="interest">
            <span>Zájem</span>
            <strong><?= (int) ($stats['interest'] ?? 0) ?></strong>
        </a>
    </section>

    <div class="adash-grid">
        <div class="adash-side">
            <section class="door-panel dash-door<?= $doorOpen ? ' is-open' : ' is-closed' ?>" data-dash-door>
                <div class="dash-door-top">
                    <div>
                        <p class="eyebrow"><?= e(strtoupper((string) ($door['provider'] ?? 'ZÁMEK'))) ?></p>
                        <h2>Dveře</h2>
                    </div>
                    <p class="dash-door-state" data-dash-door-state><?= !$configured ? 'Neznámý stav' : ($doorOpen ? 'Otevřeno' : 'Zavřeno') ?></p>
                </div>
                <div class="door-stats dash-door-stats">
                    <article class="door-stat<?= $configured && $online ? ' is-on' : ' is-off' ?>" data-dash-online>
                        <span class="door-stat-k">Spojení</span>
                        <strong><i class="door-dot" aria-hidden="true"></i><span data-dash-online-label><?= $configured && $online ? 'Online' : 'Offline' ?></span></strong>
                        <span class="door-stat-sub" data-dash-online-sub><?= $configured && $online ? 'Zámek odpovídá' : 'Zámek teď neodpovídá' ?></span>
                    </article>
                    <article class="door-stat<?= $doorBatteryLow ? ' is-low' : '' ?>" data-dash-battery-stat>
                        <span class="door-stat-k">Baterie</span>
                        <strong>
                            <span class="door-bat<?= $doorBatteryLow ? ' is-low' : '' ?>" data-dash-bat aria-hidden="true"><span class="door-bat-fill" data-dash-fill style="width: <?= $doorBattery ?? 0 ?>%"></span></span>
                            <span data-dash-battery><?= $doorBattery === null ? '—' : e((string) $doorBattery) . '%' ?></span>
                        </strong>
                        <span class="door-stat-sub" data-dash-battery-sub><?= $doorBattery === null ? 'Stav není známý' : ($doorBatteryLow ? 'Dochází, vyměň článek' : 'Nabití je v pořádku') ?></span>
                    </article>
                    <article class="door-stat door-stat-mode<?= $testMode ? ' is-test' : ' is-live' ?>" data-dash-mode>
                        <span class="door-stat-k">Režim</span>
                        <strong data-dash-mode-label><?= $testMode ? 'Test' : 'Ostrý' ?></strong>
                        <span class="door-stat-sub" data-dash-mode-sub><?= $testMode ? 'Fyzické dveře se nepohnou' : 'Příkaz jde rovnou na zámek' ?></span>
                    </article>
                </div>
                <a class="btn btn-secondary adash-panel-btn" href="<?= e(url('/user/sprava/vstup')) ?>">Ovládat dveře</a>
            </section>

        </div>
    </div>

    <nav class="adash-links" aria-label="Rychlé odkazy">
        <a href="<?= e(url('/user/sprava/rezervace')) ?>"><strong>Rezervace</strong><span>Zrušení termínů</span></a>
        <a href="<?= e(url('/user/sprava/trzby')) ?>"><strong>Tržby</strong><span>Platby a grafy</span></a>
        <a href="<?= e(url('/user/sprava/statistiky')) ?>"><strong>Statistiky</strong><span>Návštěvy a plus / minus</span></a>
        <a href="<?= e(url('/user/sprava/zakaznici')) ?>"><strong>Zákazníci</strong><span data-link-customers><?= (int) ($stats['customers'] ?? 0) ?> účtů</span></a>
        <a href="<?= e(url('/user/studio')) ?>"><strong>Studia</strong><span>Prostory</span></a>
        <a href="<?= e(url('/user/sprava/tarify')) ?>"><strong>Tarify</strong><span>Ceník</span></a>
        <a href="<?= e(url('/user/sprava/zajem')) ?>"><strong>Zájem</strong><span data-link-interest><?= (int) ($stats['interest'] ?? 0) ?> leadů</span></a>
        <a href="<?= e(url('/user/sprava/nastaveni')) ?>"><strong>Nastavení</strong><span>Pravidla</span></a>
    </nav>

</div>

<form method="post" hidden data-cust-form="cancel-reservation">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="dashboard">
    <input type="hidden" name="cancellation_reason" value="" data-cust-reason-field>
</form>

<div class="cancel-modal" data-cust-modal hidden>
    <div class="cancel-modal-backdrop" data-cust-close></div>
    <section class="cancel-modal-panel" role="dialog" aria-modal="true" aria-labelledby="adash-cancel-title">
        <p class="eyebrow" data-cust-eyebrow>Rezervace</p>
        <h2 id="adash-cancel-title" data-cust-title>Zrušit rezervaci?</h2>
        <p class="muted" data-cust-body></p>
        <div class="field" data-cust-reason-wrap hidden>
            <label for="adash-cancel-reason">Komentář pro zákazníka</label>
            <textarea id="adash-cancel-reason" data-cust-reason rows="3" maxlength="255" placeholder="Volitelné — proč se termín ruší. Zákazník to uvidí."></textarea>
        </div>
        <div class="cancel-actions">
            <button type="button" class="btn btn-secondary" data-cust-close>Zpět</button>
            <button type="button" class="btn btn-danger" data-cust-confirm>Zrušit rezervaci</button>
        </div>
    </section>
</div>
