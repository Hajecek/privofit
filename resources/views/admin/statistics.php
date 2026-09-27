<?php
$report = is_array($report ?? null) ? $report : [];
$key = (string) ($report['key'] ?? '30d');
$tabs = is_array($report['tabs'] ?? null) ? $report['tabs'] : [];
$verdict = is_array($report['verdict'] ?? null) ? $report['verdict'] : [];
$bars = is_array($report['bars'] ?? null) ? $report['bars'] : [];
$kpis = is_array($report['kpis'] ?? null) ? $report['kpis'] : [];
$visitChart = is_array($report['visit_chart'] ?? null) ? $report['visit_chart'] : ['days' => [], 'segments' => []];
$moneyChart = is_array($report['money_chart'] ?? null) ? $report['money_chart'] : ['days' => [], 'segments' => []];
$weekdays = is_array($report['weekdays'] ?? null) ? $report['weekdays'] : [];
$heat = is_array($report['heat'] ?? null) ? $report['heat'] : ['rows' => [], 'hours' => [], 'empty' => true];
$occupancy = is_array($report['occupancy'] ?? null) ? $report['occupancy'] : [];
$facts = is_array($report['facts'] ?? null) ? $report['facts'] : [];
$visitSegments = is_array($visitChart['segments'] ?? null) ? $visitChart['segments'] : [];
$moneySegments = is_array($moneyChart['segments'] ?? null) ? $moneyChart['segments'] : [];
$tone = (string) ($verdict['tone'] ?? 'flat');
if (!in_array($tone, ['up', 'down', 'flat', 'empty'], true)) {
    $tone = 'flat';
}
$visitJson = json_encode($visitChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}';
$moneyJson = json_encode($moneyChart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}';
$revenueHref = url('/user/sprava/trzby?obdobi=rozsah&od=' . ($report['start_day'] ?? '') . '&do=' . ($report['end_day'] ?? ''));
$occPercent = isset($occupancy['percent']) && $occupancy['percent'] !== null ? max(0, min(100, (int) $occupancy['percent'])) : 0;
$heatHours = is_array($heat['hours'] ?? null) ? $heat['hours'] : [];
$heatRows = is_array($heat['rows'] ?? null) ? $heat['rows'] : [];
$hourCount = count($heatHours);

$safeColor = static function (string $color): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#9aa49c';
};
?>
<div class="stats-page">
    <header class="page-head">
        <div>
            <p class="eyebrow">SPRÁVA</p>
            <h1>Statistiky</h1>
            <p class="muted"><?= e((string) ($report['label'] ?? '')) ?> · srovnání je se stejně dlouhým obdobím těsně předtím.</p>
        </div>
        <div class="page-head-actions">
            <a class="btn btn-secondary" href="<?= e($revenueHref) ?>">Tržby</a>
            <a class="btn btn-secondary" href="<?= e(url('/user/sprava')) ?>">← Přehled</a>
        </div>
    </header>

    <section class="rev-filter card" aria-label="Filtr období">
        <div class="rev-filter-presets" role="tablist" aria-label="Rychlé období">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a
                    role="tab"
                    aria-selected="<?= $key === (string) $tabKey ? 'true' : 'false' ?>"
                    class="rev-chip<?= $key === (string) $tabKey ? ' is-on' : '' ?>"
                    href="<?= e(url('/user/sprava/statistiky?obdobi=' . $tabKey)) ?>"
                ><?= e((string) $tabLabel) ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="stats-verdict card is-<?= e($tone) ?>" aria-label="Výsledek období">
        <div class="stats-verdict-copy">
            <p class="eyebrow">VÝSLEDEK</p>
            <h2 class="stats-figure"><?= e((string) ($verdict['title'] ?? '')) ?></h2>
            <p><?= e((string) ($verdict['text'] ?? '')) ?></p>
        </div>
        <div class="stats-compare" aria-hidden="true">
            <div>
                <span>Tohle období</span>
                <strong><?= e((string) ($bars['current'] ?? '0 Kč')) ?></strong>
                <i><b style="width: <?= (int) ($bars['current_width'] ?? 0) ?>%"></b></i>
            </div>
            <div>
                <span>Předchozí</span>
                <strong><?= e((string) ($bars['previous'] ?? '0 Kč')) ?></strong>
                <i><b style="width: <?= (int) ($bars['previous_width'] ?? 0) ?>%"></b></i>
            </div>
        </div>
    </section>

    <section class="ops-kpis" aria-label="Klíčové ukazatele">
        <?php foreach ($kpis as $kpi): ?>
            <?php
            $kpiTone = (string) ($kpi['tone'] ?? 'flat');
            if (!in_array($kpiTone, ['up', 'down', 'flat'], true)) {
                $kpiTone = 'flat';
            }
            $tag = !empty($kpi['href']) ? 'a' : 'article';
            ?>
            <<?= $tag ?> class="ops-kpi<?= !empty($kpi['href']) ? ' ops-kpi-link' : '' ?>"<?= !empty($kpi['href']) ? ' href="' . e($revenueHref) . '"' : '' ?>>
                <span><?= e((string) ($kpi['label'] ?? '')) ?></span>
                <strong><?= e((string) ($kpi['value'] ?? '—')) ?></strong>
                <?php if (($kpi['delta'] ?? '') !== ''): ?>
                    <span class="stats-delta is-<?= e($kpiTone) ?>"><?= e((string) $kpi['delta']) ?></span>
                <?php endif; ?>
                <?php if (($kpi['hint'] ?? '') !== ''): ?>
                    <small><?= e((string) $kpi['hint']) ?></small>
                <?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>

    <section
        class="rev-chart card"
        data-stats-visits
        data-chart="<?= e($visitJson) ?>"
        aria-label="Graf návštěvnosti"
    >
        <div class="rev-chart-head">
            <div>
                <p class="eyebrow">NÁVŠTĚVNOST</p>
                <h2 class="stats-figure"><?= e(number_format((int) ($visitChart['total'] ?? 0), 0, ',', ' ')) ?></h2>
                <p class="muted"><?= e((string) ($report['chart_note'] ?? '')) ?></p>
            </div>
        </div>
        <div class="chart-toolbar">
            <ul class="chart-split-legend" data-chart-legend hidden>
                <?php foreach ($visitSegments as $segment): ?>
                    <li><i style="background:<?= e($safeColor((string) ($segment['color'] ?? ''))) ?>"></i><?= e((string) ($segment['label'] ?? '')) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="chart-kind" role="tablist" aria-label="Typ grafu návštěvnosti">
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="line" aria-selected="false">Čára</button>
                <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="area" aria-selected="false">Plocha</button>
                <button type="button" class="chart-kind-btn is-on" role="tab" data-chart-kind="bar" aria-selected="true">Sloupce</button>
            </div>
        </div>
        <div class="adash-chart-stage">
            <canvas data-stats-line width="800" height="240" aria-label="Návštěvnost po dnech"></canvas>
            <div class="adash-chart-tip" data-chart-tip hidden></div>
        </div>
    </section>

    <div class="stats-split">
        <section
            class="rev-chart card"
            data-stats-money
            data-chart="<?= e($moneyJson) ?>"
            data-day-url="<?= e(url('/user/sprava/trzby?obdobi=den&datum=')) ?>"
            aria-label="Graf tržeb"
        >
            <div class="rev-chart-head">
                <div>
                    <p class="eyebrow">TRŽBY</p>
                    <h2 class="stats-figure"><?= e(money_format_czk($moneyChart['total'] ?? 0)) ?></h2>
                    <p
                        class="muted"
                        data-chart-hint
                        data-hint-line="<?= (int) ($report['payment_count'] ?? 0) ?> plateb · průměr <?= e((string) ($report['per_payment'] ?? '0 Kč')) ?> · klik otevře den"
                        data-hint-area="Plochy podle tarifu · klik otevře den"
                        data-hint-bar="Sloupce podle tarifu · klik otevře den"
                    ><?= (int) ($report['payment_count'] ?? 0) ?> plateb · průměr <?= e((string) ($report['per_payment'] ?? '0 Kč')) ?></p>
                </div>
            </div>
            <div class="chart-toolbar">
                <ul class="chart-split-legend" data-chart-legend hidden>
                    <?php foreach ($moneySegments as $segment): ?>
                        <li><i style="background:<?= e($safeColor((string) ($segment['color'] ?? ''))) ?>"></i><?= e((string) ($segment['label'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="chart-kind" role="tablist" aria-label="Typ grafu tržeb">
                    <button type="button" class="chart-kind-btn is-on" role="tab" data-chart-kind="line" aria-selected="true">Čára</button>
                    <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="area" aria-selected="false">Plocha</button>
                    <button type="button" class="chart-kind-btn" role="tab" data-chart-kind="bar" aria-selected="false">Sloupce</button>
                </div>
            </div>
            <div class="adash-chart-stage">
                <canvas data-stats-line width="800" height="240" aria-label="Vývoj tržeb"></canvas>
                <div class="adash-chart-tip" data-chart-tip hidden></div>
            </div>
        </section>

        <section class="card stats-mix" aria-label="Složení tržeb">
            <div class="rev-chart-head">
                <div>
                    <p class="eyebrow">PODÍL</p>
                    <h2>Z čeho jsou tržby</h2>
                    <p class="muted">Procento z čistého příjmu v období.</p>
                </div>
                <canvas data-stats-donut width="88" height="88" aria-hidden="true"></canvas>
            </div>
            <?php if ($moneySegments === []): ?>
                <p class="adash-empty">V tomhle období nejsou žádné tržby.</p>
            <?php else: ?>
                <div class="stats-stack" aria-hidden="true">
                    <?php foreach ($moneySegments as $segment): ?>
                        <?php $pct = max(0, (int) ($segment['pct'] ?? 0)); ?>
                        <?php if ($pct > 0): ?>
                            <span style="width: <?= $pct ?>%; background: <?= e($safeColor((string) ($segment['color'] ?? ''))) ?>"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <ul class="adash-legend">
                    <?php foreach ($moneySegments as $segment): ?>
                        <li>
                            <i style="background:<?= e($safeColor((string) ($segment['color'] ?? ''))) ?>"></i>
                            <span><?= e((string) ($segment['label'] ?? '')) ?></span>
                            <strong><?= (int) ($segment['pct'] ?? 0) ?> %</strong>
                            <em><?= e(money_format_czk($segment['amount'] ?? 0)) ?></em>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="stats-split">
        <section class="card stats-use" aria-label="Obsazenost">
            <div class="stats-ring" style="--p: <?= $occPercent ?>">
                <div>
                    <strong><?= !empty($occupancy['has_hours']) ? $occPercent . ' %' : '—' ?></strong>
                    <span>obsazeno</span>
                </div>
            </div>
            <div>
                <p class="eyebrow">VYUŽITÍ</p>
                <h2>Otevřená doba</h2>
                <?php if (!empty($occupancy['has_hours'])): ?>
                    <p><?= e((string) ($occupancy['booked'] ?? '')) ?> obsazeno z <?= e((string) ($occupancy['open'] ?? '')) ?>.</p>
                    <p class="muted"><?= (int) ($occupancy['rooms'] ?? 1) > 1 ? 'Podíl otevřené doby všech studií, kdy v nich někdo je.' : 'Podíl času, kdy ve studiu někdo je.' ?></p>
                    <?php if (($occupancy['prev'] ?? null) !== null): ?>
                        <p class="stats-delta is-<?= e((string) ($occupancy['tone'] ?? 'flat')) ?>">Minule <?= (int) $occupancy['prev'] ?> %</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted">Studio v tomhle období nemá otevřené hodiny.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card" aria-label="Návštěvnost podle dne">
            <p class="eyebrow">DNY</p>
            <h2>Který den je nejživější</h2>
            <?php if (!empty($report['week_empty'])): ?>
                <p class="adash-empty">V tomhle období nejsou žádné návštěvy.</p>
            <?php else: ?>
                <ul class="stats-week">
                    <?php foreach ($weekdays as $day): ?>
                        <li>
                            <span><?= e((string) ($day['short'] ?? '')) ?></span>
                            <i><b style="width: <?= max(0, min(100, (int) ($day['pct'] ?? 0))) ?>%"></b></i>
                            <strong><?= (int) ($day['count'] ?? 0) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="card stats-heat-card" aria-label="Návštěvnost podle hodiny">
        <div class="rev-chart-head">
            <div>
                <p class="eyebrow">HODINY</p>
                <h2>Kdy se chodí</h2>
                <p class="muted"><?= e((string) ($heat['caption'] ?? ($heat['source'] ?? ''))) ?></p>
            </div>
            <?php if (empty($heat['empty'])): ?>
                <p class="stats-heat-scale"><span>méně</span><i></i><span>více</span></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($heat['empty'])): ?>
            <p class="adash-empty">V tomhle období nejsou žádné návštěvy.</p>
        <?php else: ?>
            <div class="stats-heat-scroll">
                <div class="stats-heat" style="--cols: <?= $hourCount ?>">
                    <span></span>
                    <?php foreach ($heatHours as $index => $hour): ?>
                        <?php $showHour = $hourCount <= 14 || $index === 0 || $index === $hourCount - 1 || ((int) $hour % 2 === 0); ?>
                        <span class="stats-heat-hour"><?= $showHour ? (int) $hour : '' ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($heatRows as $row): ?>
                        <span class="stats-heat-day"><?= e((string) ($row['short'] ?? '')) ?></span>
                        <?php foreach ((array) ($row['cells'] ?? []) as $cell): ?>
                            <?php
                            $count = (int) ($cell['count'] ?? 0);
                            $level = max(0, min(1, (float) ($cell['level'] ?? 0)));
                            $title = trim((string) ($row['name'] ?? '') . ' ' . (int) ($cell['hour'] ?? 0) . ':00 · ' . $count);
                            ?>
                            <b class="<?= $count === 0 ? 'is-zero' : '' ?>" style="--n: <?= e(number_format($level, 2, '.', '')) ?>" title="<?= e($title) ?>"></b>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="muted stats-heat-source"><?= e((string) ($heat['source'] ?? '')) ?></p>
        <?php endif; ?>
    </section>

    <section class="stats-facts" aria-label="Další ukazatele">
        <?php foreach ($facts as $fact): ?>
            <article class="ops-kpi">
                <span><?= e((string) ($fact['label'] ?? '')) ?></span>
                <strong><?= e((string) ($fact['value'] ?? '—')) ?></strong>
                <?php if (($fact['hint'] ?? '') !== ''): ?>
                    <small><?= e((string) $fact['hint']) ?></small>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
