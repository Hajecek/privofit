<?php
$filters = [
    'nadchazejici' => 'Nadcházející',
    'dnes' => 'Dnes',
    'zrusene' => 'Zrušené',
    'vse' => 'Vše',
];
$tones = [
    'pending_payment' => ['Čeká na platbu', 'badge-warn'],
    'confirmed' => ['Potvrzeno', 'badge-ok'],
    'cancelled' => ['Zrušeno', 'badge-bad'],
    'completed' => ['Proběhlo', 'badge-done'],
    'expired' => ['Vypršelo', 'badge-muted'],
    'no_show' => ['Nedorazil', 'badge-bad'],
];
$empty = [
    'nadchazejici' => 'Žádný nadcházející termín.',
    'dnes' => 'Dnes žádná rezervace.',
    'zrusene' => 'Zatím žádná zrušená rezervace.',
    'vse' => 'Žádné rezervace.',
];
$queryBase = static function (string $key, string $q): string {
    $params = [];
    if ($key !== 'nadchazejici') {
        $params['stav'] = $key;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    return '/user/sprava/rezervace' . ($params !== [] ? '?' . http_build_query($params) : '');
};
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SPRÁVA</p>
        <h1>Rezervace</h1>
        <p class="muted">Zrušený termín se uvolní. Komentář uvidí zákazník v přehledu i v e-mailu.</p>
    </div>
</div>

<nav class="res-filters" aria-label="Filtr rezervací">
    <?php foreach ($filters as $key => $label): ?>
        <a class="res-filter<?= $filter === $key ? ' is-on' : '' ?>" href="<?= e(url($queryBase($key, $q))) ?>">
            <?= e($label) ?>
            <span><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" class="card cust-search" action="<?= e(url('/user/sprava/rezervace')) ?>">
    <?php if ($filter !== 'nadchazejici'): ?>
        <input type="hidden" name="stav" value="<?= e($filter) ?>">
    <?php endif; ?>
    <div class="field">
        <label for="ares-q">Hledat zákazníka</label>
        <input id="ares-q" name="q" value="<?= e($q) ?>" placeholder="jméno, e-mail, username" autocomplete="off">
    </div>
    <button class="btn btn-secondary" type="submit">Filtrovat</button>
</form>

<div class="table-wrap card">
    <table>
        <thead>
            <tr>
                <th>Termín</th>
                <th>Zákazník</th>
                <th>Studio</th>
                <th>Stav</th>
                <th>Cena</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted"><?= e($empty[$filter] ?? 'Žádné rezervace.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $status = (string) ($row['status'] ?? '');
            [$statusLabel, $statusClass] = $tones[$status] ?? [$status, 'badge-muted'];
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            if ($name === '') {
                $name = (string) ($row['username'] ?? 'Zákazník');
            }
            $startLocal = \App\Support\Clock::toLocal((string) $row['starts_at']);
            $endShown = \App\Support\Clock::toLocal((string) $row['ends_at'])->modify('+' . (int) ($row['buffer_minutes'] ?? 15) . ' minutes');
            $when = $startLocal->format('j. n. Y') . ' · ' . $startLocal->format('H:i') . '–' . $endShown->format('H:i');
            $canCancel = in_array($status, ['confirmed', 'pending_payment'], true) && !empty($row['public_id']);
            $reason = trim((string) ($row['cancellation_reason'] ?? ''));
            ?>
            <tr>
                <td>
                    <?= e($when) ?>
                    <?php if ((int) ($row['guest_count'] ?? 1) > 1): ?>
                        <span class="muted"> · <?= (int) $row['guest_count'] ?> os.</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['user_public_id'])): ?>
                        <a href="<?= e(url('/user/sprava/zakaznici/' . $row['user_public_id'])) ?>"><?= e($name) ?></a>
                    <?php else: ?>
                        <?= e($name) ?>
                    <?php endif; ?>
                    <?php if ($reason !== ''): ?>
                        <span class="ares-reason"><?= nl2br(e($reason)) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($row['room_name'] ?? 'Studio')) ?></td>
                <td><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                <td class="pay-facts">
                    <div class="pay-price">
                    <span><?= e(money_format_czk($row['price'] ?? 0)) ?></span>
                    <?php if (!empty($row['payment_id'])): ?>
                        <?php
                        $payment = [
                            'provider' => (string) ($row['payment_provider'] ?? ''),
                            'status' => (string) ($row['payment_status'] ?? ''),
                            'stripe_details' => $row['stripe_details'] ?? null,
                            'membership_id' => $row['membership_id'] ?? null,
                        ];
                        $factsMode = 'stack';
                        include __DIR__ . '/_payment_facts.php';
                        ?>
                    <?php elseif (!empty($row['membership_id'])): ?>
                        <span class="muted">členství</span>
                    <?php endif; ?>
                    </div>
                </td>
                <td class="interest-actions">
                    <?php if ($canCancel): ?>
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            data-cust-open="cancel-reservation"
                            data-name="<?= e($name . ' · ' . $when) ?>"
                            data-action="<?= e(url('/user/sprava/rezervace/' . $row['public_id'] . '/zrusit')) ?>"
                        >Zrušit</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form method="post" hidden data-cust-form="cancel-reservation">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="list">
    <input type="hidden" name="stav" value="<?= e($filter) ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="cancellation_reason" value="" data-cust-reason-field>
</form>

<?php require __DIR__ . '/_payment_modal.php'; ?>

<div class="cancel-modal" data-cust-modal hidden>
    <div class="cancel-modal-backdrop" data-cust-close></div>
    <section class="cancel-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ares-modal-title">
        <p class="eyebrow" data-cust-eyebrow>Rezervace</p>
        <h2 id="ares-modal-title" data-cust-title>Zrušit rezervaci?</h2>
        <p class="muted" data-cust-body></p>
        <div class="field" data-cust-reason-wrap hidden>
            <label for="ares-reason">Komentář pro zákazníka</label>
            <textarea id="ares-reason" data-cust-reason rows="3" maxlength="255" placeholder="Volitelné — proč se termín ruší. Zákazník to uvidí."></textarea>
        </div>
        <div class="cancel-actions">
            <button type="button" class="btn btn-secondary" data-cust-close>Zpět</button>
            <button type="button" class="btn btn-danger" data-cust-confirm>Zrušit rezervaci</button>
        </div>
    </section>
</div>
