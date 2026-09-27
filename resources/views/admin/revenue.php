<?php
$period = is_array($period ?? null) ? $period : [];
$payments = is_array($payments ?? null) ? $payments : [];
$summary = is_array($summary ?? null) ? $summary : [];
$chart = is_array($chart ?? null) ? $chart : ['days' => [], 'breakdown' => [], 'total' => 0, 'count' => 0];
$key = (string) ($period['key'] ?? 'dnes');
$today = \App\Support\Clock::nowLocal()->format('Y-m-d');
$fromValue = (string) ($period['from_date'] ?? ($key === 'den' ? (string) ($period['date'] ?? $today) : ($key === 'rozsah' ? '' : $today)));
$toValue = (string) ($period['to_date'] ?? ($key === 'den' ? (string) ($period['date'] ?? $today) : ($key === 'rozsah' ? '' : $today)));
if ($fromValue === '') {
    $fromValue = $today;
}
if ($toValue === '') {
    $toValue = $today;
}
$rangeOn = in_array($key, ['den', 'rozsah'], true);
$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}';
$segments = is_array($chart['segments'] ?? null) ? $chart['segments'] : [];
$showChart = count($chart['days'] ?? []) > 1;

$tabs = [
    'dnes' => 'Dnes',
    'vcera' => 'Včera',
    '7d' => '7 dní',
    '30d' => '30 dní',
    'mesic' => 'Měsíc',
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SPRÁVA</p>
        <h1>Tržby</h1>
        <p class="muted"><?= e((string) ($period['label'] ?? 'Přehled plateb')) ?></p>
    </div>
    <a class="btn btn-secondary" href="<?= e(url('/user/sprava')) ?>">← Dashboard</a>
</div>

<section class="rev-filter card" aria-label="Filtr období">
    <div class="rev-filter-presets" role="tablist" aria-label="Rychlé období">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <a
                role="tab"
                aria-selected="<?= $key === $tabKey ? 'true' : 'false' ?>"
                class="rev-chip<?= $key === $tabKey ? ' is-on' : '' ?>"
                href="<?= e(url('/user/sprava/trzby?obdobi=' . $tabKey)) ?>"
            ><?= e($tabLabel) ?></a>
        <?php endforeach; ?>
        <button
            type="button"
            role="tab"
            aria-selected="<?= $rangeOn ? 'true' : 'false' ?>"
            class="rev-chip<?= $rangeOn ? ' is-on' : '' ?>"
            data-rev-open
        >Rozmezí</button>
    </div>
    <?php if ($rangeOn): ?>
        <p class="rev-filter-note muted">Aktivní rozmezí: <?= e((string) ($period['label'] ?? '')) ?></p>
    <?php endif; ?>
</section>

<div class="cancel-modal" data-rev-modal hidden>
    <div class="cancel-modal-backdrop" data-rev-close></div>
    <div class="cancel-modal-panel rev-range-modal" role="dialog" aria-modal="true" aria-labelledby="rev-range-title">
        <p class="eyebrow">OBDOBÍ</p>
        <h2 id="rev-range-title">Vyber rozmezí</h2>
        <p class="muted rev-cal-lead">První klik = začátek, druhý klik = konec. Tržby se spočítají včetně obou dní.</p>

        <div class="rev-cal" data-cal>
            <div class="rev-cal-nav">
                <button type="button" class="rev-cal-nav-btn" data-cal-prev aria-label="Předchozí měsíc">‹</button>
                <strong data-cal-title></strong>
                <button type="button" class="rev-cal-nav-btn" data-cal-next aria-label="Další měsíc">›</button>
            </div>
            <div class="rev-cal-grid" data-cal-grid></div>
            <p class="rev-cal-hint" data-cal-hint>Klikni na počáteční den.</p>
            <button type="button" class="rev-cal-reset" data-cal-reset>Vymazat výběr</button>
        </div>

        <form method="get" action="<?= e(url('/user/sprava/trzby')) ?>" class="rev-range-form">
            <input type="hidden" name="obdobi" value="rozsah">
            <input type="hidden" name="od" value="<?= e($fromValue) ?>">
            <input type="hidden" name="do" value="<?= e($toValue) ?>">
            <div class="cancel-actions">
                <button type="button" class="btn btn-secondary" data-rev-close>Zrušit</button>
                <button class="btn btn-primary" type="submit">Zobrazit tržby</button>
            </div>
        </form>
    </div>
</div>

<section class="ops-kpis rev-summary" aria-label="Souhrn tržeb">
    <article class="ops-kpi rev-kpi-main">
        <span>Celkem</span>
        <strong><?= e(money_format_czk($summary['total'] ?? 0)) ?></strong>
    </article>
    <article class="ops-kpi">
        <span>Počet plateb</span>
        <strong><?= (int) ($summary['count'] ?? 0) ?></strong>
    </article>
    <article class="ops-kpi">
        <span>Průměr</span>
        <strong><?= e(money_format_czk($summary['average'] ?? 0)) ?></strong>
    </article>
    <article class="ops-kpi">
        <span>Rezervace</span>
        <strong><?= e(money_format_czk($summary['reservations'] ?? 0)) ?></strong>
    </article>
    <article class="ops-kpi">
        <span>Členství</span>
        <strong><?= e(money_format_czk($summary['memberships'] ?? 0)) ?></strong>
    </article>
    <article class="ops-kpi">
        <span>Ostatní</span>
        <strong><?= e(money_format_czk($summary['other'] ?? 0)) ?></strong>
    </article>
</section>

<?php if ($showChart): ?>
<section
    class="rev-chart card"
    data-rev-chart
    data-chart="<?= e($chartJson) ?>"
    data-day-url="<?= e(url('/user/sprava/trzby?obdobi=den&datum=')) ?>"
    aria-label="Graf tržeb"
>
    <div class="rev-chart-head">
        <div>
            <p class="eyebrow">VÝVOJ</p>
            <h2>Příjem po dnech</h2>
            <p
                class="muted"
                data-chart-hint
                data-hint-line="Najetí ukáže součet · klik otevře den"
                data-hint-area="Plochy podle tarifu · klik otevře den"
                data-hint-bar="Sloupce podle tarifu · klik otevře den"
            >Najetí ukáže součet · klik otevře den</p>
        </div>
        <div class="rev-chart-donut">
            <canvas data-rev-donut width="88" height="88" aria-hidden="true"></canvas>
            <ul class="adash-legend">
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
        <canvas data-rev-line width="800" height="240" aria-label="Vývoj tržeb"></canvas>
        <div class="adash-chart-tip" data-chart-tip hidden></div>
    </div>
</section>
<?php endif; ?>

<section class="card door-admin-panel">
    <div class="door-admin-panel-head">
        <div>
            <p class="eyebrow">PLATBY</p>
            <h2>Detail období</h2>
            <p class="muted">Zaplacené platby · <?= e((string) ($period['label'] ?? '')) ?></p>
        </div>
        <span class="badge badge-muted"><?= (int) ($summary['count'] ?? 0) ?></span>
    </div>

    <?php if ($payments === []): ?>
        <p class="door-admin-empty muted">V tomto období nejsou žádné zaplacené platby.</p>
    <?php else: ?>
        <div class="table-wrap door-admin-table">
            <table>
                <thead>
                    <tr>
                        <th>Čas</th>
                        <th>Zákazník</th>
                        <th>Typ</th>
                        <th>Částka</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <?php
                    $who = trim((string) (($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')));
                    if ($who === '') {
                        $who = (string) ($payment['username'] ?? '—');
                    }
                    if (!empty($payment['reservation_id'])) {
                        $type = 'Rezervace';
                        $typeClass = 'badge-ok';
                    } elseif (!empty($payment['membership_id'])) {
                        $type = 'Členství';
                        $typeClass = 'badge-warn';
                    } else {
                        $type = 'Ostatní';
                        $typeClass = 'badge-muted';
                    }
                    $paidAt = !empty($payment['paid_at']) ? format_datetime((string) $payment['paid_at']) : '—';
                    ?>
                    <tr>
                        <td><?= e($paidAt) ?></td>
                        <td>
                            <?php if (!empty($payment['user_public_id'])): ?>
                                <a href="<?= e(url('/user/sprava/zakaznici/' . $payment['user_public_id'])) ?>"><?= e($who) ?></a>
                            <?php else: ?>
                                <?= e($who) ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= e($typeClass) ?>"><?= e($type) ?></span></td>
                        <td class="pay-facts">
                            <div class="pay-price">
                                <span><?= e(money_format_czk($payment['amount'] ?? 0)) ?></span>
                                <?php $factsMode = 'stack'; include __DIR__ . '/_payment_facts.php'; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_payment_modal.php'; ?>
