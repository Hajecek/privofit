<?php
$stats = is_array($stats ?? null) ? $stats : [];
$chart = is_array($chart ?? null) ? $chart : ['days' => [], 'breakdown' => [], 'total' => 0, 'count' => 0];
$current = is_array($stats['current'] ?? null) ? $stats['current'] : [];
$door = is_array($stats['door'] ?? null) ? $stats['door'] : [];
$todayList = is_array($stats['today_list'] ?? null) ? $stats['today_list'] : [];
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
                <h2 data-chart-total><?= e(money_format_czk($chart['total'] ?? $stats['revenue'] ?? 0)) ?></h2>
                <p
                    class="muted"
                    data-chart-hint
                    data-hint-line="Posledních 30 dní · klikni na den"
                    data-hint-area="Plochy podle tarifu · klikni na den"
                    data-hint-bar="Sloupce podle tarifu · klikni na den"
                >Posledních 30 dní · klikni na den</p>
            </div>
            <div class="adash-chart-actions">
                <a class="btn btn-secondary" href="<?= e(url('/user/sprava/trzby?obdobi=dnes')) ?>">Dnes</a>
                <a class="btn btn-primary" href="<?= e(url('/user/sprava/trzby')) ?>">Detail tržeb</a>
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
            <div class="chart-kind" role="tablist" aria-label="Typ grafu">
                <button type="button" class="chart-kind-btn is-on" role="tab" data-chart-kind="line" aria-selected="true">Čára</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="area" aria-selected="false">Plocha</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="bar" aria-selected="false">Sloupce</button>
            </div>
        </div>
        <div class="adash-chart-stage">
            <canvas data-dash-line width="800" height="260" aria-label="Vývoj tržeb"></canvas>
            <div class="adash-chart-tip" data-chart-tip hidden></div>
        </div>

        <div class="adash-chart-footer">
            <div class="adash-donut-wrap">
                <canvas data-dash-donut width="120" height="120" aria-hidden="true"></canvas>
                <ul class="adash-legend" data-chart-donut-legend>
                    <?php foreach ($segments as $segment): ?>
                        <?php
                        $segmentColor = (string) ($segment['color'] ?? '');
                        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $segmentColor)) {
                            $segmentColor = '#9aa49c';
                        }
                        ?>
                        <li><i style="background:<?= e($segmentColor) ?>"></i><span><?= e((string) ($segment['label'] ?? '')) ?></span> <strong><?= e(money_format_czk($segment['amount'] ?? 0)) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="adash-mini-kpis">
                <a href="<?= e(url('/user/sprava/trzby?obdobi=dnes')) ?>">
                    <span>Dnes</span>
                    <strong data-rev-today><?= e(money_format_czk($stats['revenue_today'] ?? 0)) ?></strong>
                </a>
                <a href="<?= e(url('/user/sprava/trzby?obdobi=vcera')) ?>">
                    <span>Včera</span>
                    <strong data-rev-yesterday><?= e(money_format_czk($stats['revenue_yesterday'] ?? 0)) ?></strong>
                </a>
                <a href="<?= e(url('/user/sprava/trzby?obdobi=30d')) ?>">
                    <span>Plateb / 30 dní</span>
                    <strong data-rev-count><?= (int) ($stats['revenue_count_30'] ?? ($chart['count'] ?? 0)) ?></strong>
                </a>
            </div>
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
        <section class="adash-panel">
            <div class="adash-panel-head">
                <div>
                    <p class="eyebrow">DNES</p>
                    <h2>Harmonogram</h2>
                </div>
                <span class="badge badge-muted" data-dash-schedule-count><?= count($todayList) ?></span>
            </div>
            <div data-dash-schedule-body>
            <?php if ($occupied && $reservation): ?>
                <div class="adash-now">
                    <span class="eyebrow">PRÁVĚ TEĎ</span>
                    <strong><?= e($guestName !== '' ? $guestName : 'Zákazník') ?></strong>
                    <span><?= e($slotLabel !== '' ? $slotLabel : 'Probíhající termín') ?></span>
                </div>
            <?php endif; ?>
            <?php if ($todayList === []): ?>
                <p class="adash-empty">Dnes žádné rezervace.<?= $nextLabel !== '' ? ' Další termín ' . e($nextLabel) . '.' : '' ?></p>
            <?php else: ?>
                <ul class="adash-timeline">
                    <?php foreach ($todayList as $row): ?>
                        <?php
                        $rs = \App\Support\Clock::toLocal((string) $row['starts_at']);
                        $re = $blockEnd((string) $row['ends_at'], $row['buffer_minutes'] ?? 15);
                        $endClock = $re->format('Y-m-d') === $rs->format('Y-m-d') ? $re->format('H:i') : $re->format('j. n. H:i');
                        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                        if ($name === '') {
                            $name = (string) ($row['username'] ?? 'Zákazník');
                        }
                        $isLive = $occupied && $reservation && (int) ($reservation['id'] ?? 0) === (int) ($row['id'] ?? 0);
                        $isPast = $re < $nowLocal;
                        $pending = ($row['status'] ?? '') === 'pending_payment';
                        ?>
                        <li class="adash-tl<?= $isLive ? ' is-live' : ($isPast ? ' is-past' : '') ?>">
                            <time><?= e($rs->format('H:i')) ?><span>–<?= e($endClock) ?></span></time>
                            <div>
                                <?php if (!empty($row['user_public_id'])): ?>
                                    <a href="<?= e(url('/user/sprava/zakaznici/' . $row['user_public_id'])) ?>"><?= e($name) ?></a>
                                <?php else: ?>
                                    <strong><?= e($name) ?></strong>
                                <?php endif; ?>
                                <span><?= e((string) ($row['room_name'] ?? 'Studio')) ?><?= $pending ? ' · čeká na platbu' : '' ?></span>
                            </div>
                            <div class="adash-tl-side">
                            <?php if ($isLive): ?>
                                <span class="badge badge-warn">Teď</span>
                            <?php elseif ($pending): ?>
                                <span class="badge badge-muted">Platba</span>
                            <?php elseif ($isPast): ?>
                                <span class="badge badge-done">Hotovo</span>
                            <?php else: ?>
                                <span class="badge badge-ok">Čeká</span>
                            <?php endif; ?>
                            <?php if (in_array((string) ($row['status'] ?? ''), ['confirmed', 'pending_payment'], true) && !empty($row['public_id'])): ?>
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    data-cust-open="cancel-reservation"
                                    data-name="<?= e($name . ' · ' . $rs->format('H:i') . '–' . $endClock) ?>"
                                    data-action="<?= e(url('/user/sprava/rezervace/' . $row['public_id'] . '/zrusit')) ?>"
                                >Zrušit</button>
                            <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            </div>
        </section>

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
