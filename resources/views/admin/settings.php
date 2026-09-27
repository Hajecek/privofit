<?php
$audit = is_array($audit ?? null) ? $audit : [];
$slot = (int) setting('reservation.slot_minutes', 15);
$min = (int) setting('reservation.min_minutes', 60);
$max = (int) setting('reservation.max_minutes', 1440);
$buffer = (int) setting('reservation.buffer_minutes', 15);
$cancel = (int) setting('reservation.cancellation_hours', 12);
$early = (int) setting('access.early_minutes', 5);
$late = (int) setting('access.late_minutes', 5);
$blockMinutes = $min + $buffer;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SPRÁVA</p>
        <h1>Nastavení</h1>
        <p class="muted">Rezervace, storno a limity vstupu do studia.</p>
    </div>
</div>

<section class="entry-hero card settings-admin-hero is-open">
    <div>
        <p class="eyebrow">Systém</p>
        <h2>Pravidla studia</h2>
        <p class="entry-lead">Tyhle hodnoty řídí kalendář, storno i okno, kdy jde otevřít dveře.</p>
        <div class="entry-facts door-admin-facts">
            <div>
                <span>Blok</span>
                <strong><?= $blockMinutes ?> min</strong>
            </div>
            <div>
                <span>Storno</span>
                <strong><?= $cancel ?> h</strong>
            </div>
            <div>
                <span>Vstup dřív</span>
                <strong><?= $early ?> min</strong>
            </div>
            <div>
                <span>Audit</span>
                <strong><?= count($audit) ?></strong>
            </div>
        </div>
    </div>
    <div class="entry-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
        </svg>
    </div>
</section>

<form method="post" class="settings-admin-form">
    <?= csrf_field() ?>

    <section class="card door-admin-panel">
        <div class="door-admin-panel-head">
            <div>
                <p class="eyebrow">REZERVACE</p>
                <h2>Kalendář a bloky</h2>
                <p class="muted">Interní krok obsazenosti. Zákazník vidí bloky 1 h + rezervu na převlečení.</p>
            </div>
        </div>
        <div class="settings-admin-fields">
            <div class="field">
                <label for="setting-slot">Krok začátku (min)</label>
                <input id="setting-slot" name="reservation_slot_minutes" inputmode="numeric" value="<?= e((string) $slot) ?>">
                <p class="muted">Nejmenší posun, o který se posouvá dostupnost v kalendáři.</p>
            </div>
            <div class="field">
                <label for="setting-min">Min. délka tréninku (min)</label>
                <input id="setting-min" name="reservation_min_minutes" inputmode="numeric" value="<?= e((string) $min) ?>">
            </div>
            <div class="field">
                <label for="setting-max">Max. délka tréninku (min)</label>
                <input id="setting-max" name="reservation_max_minutes" inputmode="numeric" value="<?= e((string) $max) ?>">
                <p class="muted">Strop v minutách tréninku. 1440 dovolí rezervaci až na celou otevírací dobu.</p>
            </div>
            <div class="field">
                <label for="setting-buffer">Rezerva na převlečení (min)</label>
                <input id="setting-buffer" name="reservation_buffer_minutes" inputmode="numeric" value="<?= e((string) $buffer) ?>">
                <p class="muted">Patří do každého bloku. Víc bloků za sebou se drží celé, klidně na celý den.</p>
            </div>
            <div class="field">
                <label for="setting-cancel">Storno (hodiny)</label>
                <input id="setting-cancel" name="reservation_cancellation_hours" inputmode="numeric" value="<?= e((string) $cancel) ?>">
                <p class="muted">Do kdy jde rezervaci zrušit bez sankce.</p>
            </div>
        </div>
    </section>

    <section class="card door-admin-panel">
        <div class="door-admin-panel-head">
            <div>
                <p class="eyebrow">VSTUP</p>
                <h2>Okno zámku</h2>
                <p class="muted">Dveře drží celou rezervaci včetně času na převlečení.</p>
            </div>
        </div>
        <div class="settings-admin-fields">
            <div class="field">
                <label for="setting-early">Vstup před začátkem (min)</label>
                <input id="setting-early" name="access_early_minutes" inputmode="numeric" value="<?= e((string) $early) ?>">
                <p class="muted">Jak brzy před startem termínu jde dveře otevřít.</p>
            </div>
            <div class="field">
                <label for="setting-late">Vstup po konci tréninku (min)</label>
                <input id="setting-late" name="access_late_minutes" inputmode="numeric" value="<?= e((string) $late) ?>">
                <p class="muted">Pro zámek se už nepoužívá. Pole zůstává kvůli kompatibilitě.</p>
            </div>
        </div>
    </section>

    <div class="settings-admin-actions">
        <button class="btn btn-primary" type="submit">Uložit nastavení</button>
    </div>
</form>

<section class="card door-admin-panel">
    <div class="door-admin-panel-head">
        <div>
            <p class="eyebrow">HISTORIE</p>
            <h2>Auditní log</h2>
            <p class="muted">Posledních <?= count($audit) ?> admin zásahů do systému.</p>
        </div>
    </div>
    <?php if ($audit === []): ?>
        <p class="door-admin-empty muted">Zatím žádné záznamy.</p>
    <?php else: ?>
        <div class="table-wrap door-admin-table">
            <table>
                <thead>
                    <tr>
                        <th>Čas</th>
                        <th>Kdo</th>
                        <th>Akce</th>
                        <th>Entita</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($audit as $row): ?>
                    <tr>
                        <td><?= e(format_datetime((string) ($row['created_at'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['username'] ?? '—')) ?></td>
                        <td><span class="badge badge-muted"><?= e((string) ($row['action'] ?? '')) ?></span></td>
                        <td class="muted"><?= e((string) ($row['entity_type'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
