<?php
$hourly = (float) ($availability['hourly_price'] ?? 150);
$step = (int) ($availability['duration_step_minutes'] ?? 60);
$min = (int) ($availability['min_minutes'] ?? 60);
$max = (int) ($availability['max_minutes'] ?? 1440);
$buffer = (int) ($availability['buffer_minutes'] ?? 15);
$maxPersons = (int) ($availability['max_persons'] ?? 2);
$dayNames = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];
$dowShort = [1 => 'PO', 2 => 'ÚT', 3 => 'ST', 4 => 'ČT', 5 => 'PÁ', 6 => 'SO', 7 => 'NE'];
$monthsGen = [1 => 'ledna', 2 => 'února', 3 => 'března', 4 => 'dubna', 5 => 'května', 6 => 'června', 7 => 'července', 8 => 'srpna', 9 => 'září', 10 => 'října', 11 => 'listopadu', 12 => 'prosince'];
$monthsShort = [1 => 'LED', 2 => 'ÚNO', 3 => 'BŘE', 4 => 'DUB', 5 => 'KVĚ', 6 => 'ČVN', 7 => 'ČVC', 8 => 'SRP', 9 => 'ZÁŘ', 10 => 'ŘÍJ', 11 => 'LIS', 12 => 'PRO'];
$localDay = \App\Support\Clock::parseLocal($date . ' 12:00:00');
$dateLabel = $dayNames[(int) $localDay->format('N')] . ' ' . (int) $localDay->format('j') . '. ' . $monthsGen[(int) $localDay->format('n')];
$sheetMonth = $monthsShort[(int) $localDay->format('n')];
$sheetDow = $dowShort[(int) $localDay->format('N')];
$sheetDay = (int) $localDay->format('j');
$payload = [
    'date' => $date,
    'today' => $today,
    'availability' => $availability,
    'membership_covers' => !empty($membership_covers),
    'entries_remaining' => $entries_remaining,
    'stripeFee' => \App\Services\Billing\StripeFee::rates(),
    'advanceDays' => max(1, min(365, (int) setting('reservation.advance_days', 56))),
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">TVŮJ ČAS</p>
        <h1>Rezervace</h1>
        <p class="muted">Otevři kalendář, vyber den a klikni na první okénko. Další přidáš tlačítkem nebo kliknutím dál v řadě, klidně až do konce dne. Každý blok zůstane 1 h 15 min.</p>
    </div>
    <a class="button" href="<?= e(url('/user/moje-rezervace')) ?>">Moje rezervace</a>
</div>

<div
    class="booker"
    data-booker
    data-store="<?= e(url('/user/rezervace')) ?>"
    data-availability-url="<?= e(url('/user/rezervace/dostupnost')) ?>"
    data-calendar-url="<?= e(url('/user/rezervace/kalendar')) ?>"
    data-page-url="<?= e(url('/user/rezervace')) ?>"
    data-room="<?= e((string) ($room['public_id'] ?? '')) ?>"
    data-payload="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP)) ?>"
>
    <noscript>
        <form class="card" method="get" action="<?= e(url('/user/rezervace')) ?>" style="margin-bottom:16px">
            <div class="field"><label>Datum</label><input type="date" name="date" value="<?= e($date) ?>"></div>
            <button class="btn btn-secondary">Zobrazit den</button>
        </form>
    </noscript>

    <?php if (count($rooms ?? []) > 1): ?>
        <div class="studio-rooms">
            <?php foreach ($rooms as $item): ?>
                <a class="studio-room<?= ($room['public_id'] ?? '') === $item['public_id'] ? ' is-on' : '' ?>" href="<?= e(url('/user/rezervace?date=' . rawurlencode($date) . '&room=' . rawurlencode((string) $item['public_id']))) ?>">
                    <strong><?= e($item['name']) ?></strong>
                    <span><?= e($item['location'] ?: 'Prostor') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <button type="button" class="cal-trigger" data-open-cal aria-haspopup="dialog" aria-expanded="false">
        <span class="cal-sheet" aria-hidden="true">
            <span class="cal-sheet-rings"><i></i><i></i></span>
            <span class="cal-sheet-month" data-sheet-month><?= e($sheetMonth) ?></span>
            <span class="cal-sheet-day" data-sheet-day><?= (int) $sheetDay ?></span>
            <span class="cal-sheet-dow" data-sheet-dow><?= e($sheetDow) ?></span>
        </span>
        <span class="cal-trigger-copy">
            <span class="cal-trigger-kicker">Kalendář</span>
            <strong data-date-label><?= e($dateLabel) ?></strong>
            <span class="cal-trigger-hint">Klikni a vyber den</span>
        </span>
        <span class="cal-trigger-go" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 6l6 6-6 6"/>
            </svg>
        </span>
    </button>

    <div class="cal-modal" data-cal-modal hidden>
        <div class="cal-modal-backdrop" data-cal-close></div>
        <section class="cal-modal-panel" data-calendar role="dialog" aria-modal="true" aria-labelledby="calendar-title">
            <header class="cal-modal-top">
                <div>
                    <p class="cal-modal-kicker">Kalendář</p>
                    <h2 id="calendar-title">Vyber den</h2>
                </div>
                <button type="button" class="cal-modal-x" data-cal-close aria-label="Zavřít kalendář">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </header>
            <div class="cal-head">
                <button type="button" class="cal-nav" data-cal-prev aria-label="Předchozí měsíc">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
                </button>
                <h3 data-cal-title>Kalendář</h3>
                <button type="button" class="cal-nav" data-cal-next aria-label="Další měsíc">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </div>
            <div class="cal-weekdays" aria-hidden="true">
                <span>Po</span><span>Út</span><span>St</span><span>Čt</span><span>Pá</span><span>So</span><span>Ne</span>
            </div>
            <div class="cal-grid" data-cal-grid></div>
            <footer class="cal-foot">
                <p class="cal-legend"><i class="cal-key is-free"></i> Volný termín <i class="cal-key is-mine"></i> Tvoje rezervace</p>
                <div class="cal-foot-actions">
                    <button type="button" class="cal-today" data-cal-today>Dnes</button>
                    <button type="button" class="cal-modal-close" data-cal-close>Zavřít</button>
                </div>
            </footer>
        </section>
    </div>

    <section class="booker-hours card">
        <div class="booker-hours-head">
            <div>
                <h2>Hodiny</h2>
                <p class="muted" data-hours-hint>Každý blok je hodina tréninku plus <?= (int) $buffer ?> min úklid. Okének za sebou můžeš vybrat víc, klidně na celý volný den.</p>
            </div>
            <p class="booker-price"><?= e(money_format_czk($hourly)) ?><span> / hod</span></p>
        </div>
        <div class="hour-list" data-hour-list></div>
        <p class="booker-empty" data-hours-empty hidden>Pro tento den teď není volná hodina.</p>
        <div class="booker-closed" data-hours-closed hidden>
            <div class="booker-closed-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle cx="12" cy="12" r="8"/>
                    <path d="M12 8v4l2.5 1.5"/>
                </svg>
            </div>
            <div class="booker-closed-copy">
                <strong data-closed-title>Bohužel je dnes zavřeno</strong>
                <p data-closed-text>Vyber jiný den v kalendáři a rezervuj si volný termín.</p>
            </div>
            <button type="button" class="btn btn-secondary button-small" data-open-cal>Vybrat jiný den</button>
        </div>
        <p class="booker-empty" data-hours-loading hidden>Načítám volné hodiny…</p>
    </section>

    <form id="book-form" method="post" action="<?= e(url('/user/rezervace')) ?>" data-book-form>
        <?= csrf_field() ?>
        <input type="hidden" name="start" value="">
        <input type="hidden" name="duration" value="<?= (int) $min ?>">
        <input type="hidden" name="guests" value="1">
        <input type="hidden" name="room" value="<?= e((string) ($room['public_id'] ?? '')) ?>">
        <input type="hidden" name="pay" value="0">
    </form>
</div>

<div class="booker-bar" data-bar hidden>
    <div class="booker-bar-inner">
        <button type="button" class="booker-bar-clear" data-clear aria-label="Zrušit výběr">✕</button>
        <div class="booker-bar-copy">
            <strong data-bar-time>Vyber hodiny</strong>
            <span data-bar-meta>Klikni na volnou hodinu v seznamu</span>
        </div>
        <div class="booker-durations" data-durations>
            <span>Okénka</span>
            <button type="button" data-hours-minus aria-label="Méně okének">−</button>
            <strong data-hours-count>1 okénko</strong>
            <button type="button" data-hours-plus aria-label="Více okének">+</button>
            <button type="button" data-hours-all>Celý den</button>
        </div>
        <div class="booker-guests" data-guests-wrap>
            <span>Osoby</span>
            <button type="button" data-guest-minus aria-label="Méně osob">−</button>
            <strong data-guest-count>1</strong>
            <button type="button" data-guest-plus aria-label="Více osob">+</button>
        </div>
        <div class="booker-actions">
            <button type="submit" class="btn btn-secondary" data-pay form="book-form">Zaplatit</button>
            <button type="submit" class="btn btn-primary" data-confirm form="book-form">Rezervovat</button>
        </div>
    </div>
</div>
