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
<div class="pay-stage">
<header class="pay-head">
    <p class="eyebrow">Platba</p>
    <h1><?= e((string) $summary['title']) ?></h1>
    <p class="muted">Částka se dopočítá podle země karty, ještě než se peníze strhnou.</p>
</header>

<section class="card pay-checkout">
    <div class="pay-summary">
    <div class="pay-total">
        <span>K zaplacení</span>
        <strong data-charge><?= e($crowns((string) $shown['charge'])) ?></strong>
    </div>
    <dl class="pay-lines">
        <div>
            <dt>Cena</dt>
            <dd><?= e($crowns((string) $shown['net'])) ?></dd>
        </div>
        <div>
            <dt data-fee-label>Poplatek evropské karty</dt>
            <dd data-fee><?= e($crowns((string) $shown['fee'])) ?></dd>
        </div>
    </dl>
    <ul class="pay-bands">
        <li>Evropa <?= e($crowns((string) $variants['eea']['fee'])) ?></li>
        <li>Británie <?= e($crowns((string) $variants['gb']['fee'])) ?></li>
        <li>Ostatní a Link <?= e($crowns((string) $variants['international']['fee'])) ?></li>
    </ul>
    <p class="pay-note" data-note hidden></p>
    </div>
    <?php if ($ready): ?>
        <div class="pay-methods">
            <div class="pay-wallets" data-wallets>
                <div id="express-checkout"></div>
            </div>
            <p class="pay-or" data-pay-or hidden><span>nebo kartou</span></p>
            <div id="payment-element"></div>
            <p class="pay-error" data-error hidden></p>
            <div class="pay-actions">
                <button class="button" type="button" data-submit>Zaplatit <?= e($crowns((string) $shown['charge'])) ?></button>
                <a class="btn btn-secondary" href="<?= e(url((string) $page['cancelUrl'])) ?>">Zrušit</a>
            </div>
        </div>
    <?php else: ?>
        <div class="pay-methods">
            <p class="pay-error">Platba kartou teď není dostupná.</p>
            <a class="btn btn-secondary" href="<?= e(url((string) $page['cancelUrl'])) ?>">Zpět</a>
        </div>
    <?php endif; ?>
</section>
</div>
<?php if ($ready): ?>
<script nonce="<?= e($cspNonce ?? '') ?>" src="https://js.stripe.com/v3/"></script>
<script nonce="<?= e($cspNonce ?? '') ?>" type="application/json" id="pay-config"><?= json_encode([
    'publishableKey' => $key,
    'platba' => (string) $page['payment']['public_id'],
    'title' => (string) $summary['title'],
    'confirmUrl' => url('/user/platba/potvrdit'),
    'chargeMinor' => (int) $shown['chargeMinor'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
