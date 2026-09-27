<?php
/** @var array<string, mixed> $page */
$summary = $page['summary'];
$shown = $page['shown'];
$variants = $page['variants'];
$crowns = static function (string|float|int $amount): string {
    return number_format((float) $amount, 2, ',', ' ') . ' Kč';
};
$key = (string) ($page['publishableKey'] ?? '');
$ready = str_starts_with($key, 'pk_');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Platba</p>
        <h1><?= e((string) $summary['title']) ?></h1>
        <p class="muted">Poplatek se dopočítá podle země karty, ještě než se peníze strhnou.</p>
    </div>
</div>

<section class="card pay-checkout">
    <dl class="pay-sheet">
        <dt>Cena</dt>
        <dd><?= e($crowns((string) $shown['net'])) ?></dd>
        <dt data-fee-label>Poplatek evropské karty</dt>
        <dd data-fee><?= e($crowns((string) $shown['fee'])) ?></dd>
        <dt>K zaplacení</dt>
        <dd data-charge><?= e($crowns((string) $shown['charge'])) ?></dd>
    </dl>
    <p class="muted pay-bands">
        Evropská karta <?= e($crowns((string) $variants['eea']['fee'])) ?>,
        britská <?= e($crowns((string) $variants['gb']['fee'])) ?>,
        ostatní včetně Linku se zahraniční kartou <?= e($crowns((string) $variants['international']['fee'])) ?>.
    </p>
    <p class="pay-note" data-note hidden></p>
    <?php if ($ready): ?>
        <div id="payment-element"></div>
        <p class="pay-error" data-error hidden></p>
        <div class="pay-actions">
            <button class="button" type="button" data-submit data-charge-minor="<?= (int) $shown['chargeMinor'] ?>">Zaplatit <?= e($crowns((string) $shown['charge'])) ?></button>
            <a class="btn btn-secondary" href="<?= e(url((string) $page['cancelUrl'])) ?>">Zrušit</a>
        </div>
    <?php else: ?>
        <p class="pay-error">Platba kartou teď není dostupná.</p>
        <a class="btn btn-secondary" href="<?= e(url((string) $page['cancelUrl'])) ?>">Zpět</a>
    <?php endif; ?>
</section>
<?php if ($ready): ?>
<script nonce="<?= e($cspNonce ?? '') ?>" src="https://js.stripe.com/v3/"></script>
<script nonce="<?= e($cspNonce ?? '') ?>" type="application/json" id="pay-config"><?= json_encode([
    'publishableKey' => $key,
    'platba' => (string) $page['payment']['public_id'],
    'confirmUrl' => url('/user/platba/potvrdit'),
    'chargeMinor' => (int) $shown['chargeMinor'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
