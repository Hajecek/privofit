<?php
$hourly = (float) ($availability['hourly_price'] ?? 150);
$step = (int) ($availability['duration_step_minutes'] ?? 60);
$min = (int) ($availability['min_minutes'] ?? 60);
$max = (int) ($availability['max_minutes'] ?? 1440);
$buffer = (int) ($availability['buffer_minutes'] ?? 15);
$maxPersons = (int) ($availability['max_persons'] ?? 2);
$dayNames = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];
$monthsGen = [1 => 'ledna', 2 => 'února', 3 => 'března', 4 => 'dubna', 5 => 'května', 6 => 'června', 7 => 'července', 8 => 'srpna', 9 => 'září', 10 => 'října', 11 => 'listopadu', 12 => 'prosince'];
$localDay = \App\Support\Clock::parseLocal($date . ' 12:00:00');
$dateLabel = $dayNames[(int) $localDay->format('N')] . ' ' . (int) $localDay->format('j') . '. ' . $monthsGen[(int) $localDay->format('n')];
$payload = [
    'date' => $date,
    'today' => $today,
    'availability' => $availability,
    'membership_covers' => !empty($membership_covers),
    'entries_remaining' => $entries_remaining,
    'stripeFee' => \App\Services\Billing\StripeFee::rates(),
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

    <button type="button" class="booker-date-btn card" data-open-cal aria-haspopup="dialog" aria-expanded="false">
        <span class="booker-date-kicker">Vybraný den</span>
        <strong data-date-label><?= e($dateLabel) ?></strong>
        <span class="booker-date-action">Otevřít kalendář</span>
    </button>

    <div class="cal-modal" data-cal-modal hidden>
        <div class="cal-modal-backdrop" data-cal-close></div>
        <section class="cal-modal-panel" data-calendar role="dialog" aria-modal="true" aria-labelledby="calendar-title">
            <header class="cal-head">
                <button type="button" class="cal-nav" data-cal-prev aria-label="Předchozí měsíc">‹</button>
                <h2 id="calendar-title" data-cal-title>Kalendář</h2>
                <button type="button" class="cal-nav" data-cal-next aria-label="Další měsíc">›</button>
            </header>
            <div class="cal-weekdays" aria-hidden="true">
                <span>Po</span><span>Út</span><span>St</span><span>Čt</span><span>Pá</span><span>So</span><span>Ne</span>
            </div>
            <div class="cal-grid" data-cal-grid></div>
            <p class="cal-legend muted"><i class="cal-key is-free"></i> Volný termín <i class="cal-key is-mine"></i> Tvoje rezervace</p>
            <button type="button" class="cal-modal-close" data-cal-close>Zavřít</button>
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
