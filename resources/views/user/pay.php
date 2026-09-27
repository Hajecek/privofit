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
    <?php if (!empty($summary['date'])): ?>
        <p class="eyebrow">Rezervace</p>
        <h1><?= e((string) $summary['date']) ?></h1>
        <p class="pay-when"><?= e((string) $summary['time']) ?></p>
    <?php else: ?>
        <p class="eyebrow">Platba</p>
        <h1><?= e((string) $summary['title']) ?></h1>
    <?php endif; ?>
    <p class="muted">Apple Pay a Google Pay mají uvedenou cenu. U karty se typ pozná sám a strhne se podle něj.</p>
</header>

<section class="card pay-checkout">
    <div class="pay-summary">
    <?php
    $choiceRows = [
        ['key' => 'apple', 'name' => 'Apple Pay', 'band' => 'eea', 'label' => 'Poplatek evropské karty', 'low' => true],
        ['key' => 'google', 'name' => 'Google Pay', 'band' => 'eea', 'label' => 'Poplatek evropské karty', 'low' => false],
    ];
    $cardNotes = [
        ['name' => 'Evropská karta', 'band' => 'eea'],
        ['name' => 'Britská karta', 'band' => 'gb'],
        ['name' => 'Zahraniční karta a Link', 'band' => 'international'],
    ];
    ?>
    <div class="pay-choices">
        <?php foreach ($choiceRows as $choice): ?>
            <?php $quote = $variants[$choice['band']]; ?>
            <button class="pay-choice<?= $choice['low'] ? ' is-on' : '' ?>" type="button" data-choice="<?= e($choice['key']) ?>" data-minor="<?= e((string) $quote['chargeMinor']) ?>">
                <span>
                    <?= e($choice['name']) ?>
                    <?php if ($choice['low']): ?><small>Nejnižší</small><?php endif; ?>
                </span>
                <strong><?= e($crowns((string) $quote['charge'])) ?></strong>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="pay-info">
        <p>Platba kartou <span>typ se pozná sám</span></p>
        <?php foreach ($cardNotes as $note): ?>
            <div>
                <span><?= e($note['name']) ?></span>
                <strong><?= e($crowns((string) $variants[$note['band']]['charge'])) ?></strong>
            </div>
        <?php endforeach; ?>
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
    </div>
    <?php if ($ready): ?>
        <div class="pay-methods">
            <div class="pay-wallets" data-wallets>
                <div id="express-checkout"></div>
            </div>
            <p class="pay-or" data-pay-or hidden><span>nebo kartou</span></p>
            <div id="payment-element"></div>
            <p class="pay-error" data-error hidden></p>
        </div>
        <footer class="pay-payoff">
            <div class="pay-due">
                <span>Zaplatíte</span>
                <strong data-charge><?= e($crowns((string) $shown['charge'])) ?></strong>
            </div>
            <div class="pay-actions">
                <p class="pay-wallet-hint" data-wallet-hint>Zaplať tlačítkem Apple Pay.</p>
                <button class="button" type="button" data-submit>Zaplatit kartou</button>
                <a class="btn btn-secondary" href="<?= e(url((string) $page['cancelUrl'])) ?>">Zrušit</a>
            </div>
        </footer>
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
    'quotes' => array_combine(
        array_column($choiceRows, 'key'),
        array_map(static function (array $choice) use ($variants): array {
            $quote = $variants[$choice['band']];
            return [
                'chargeMinor' => (int) $quote['chargeMinor'],
                'fee' => (string) $quote['fee'],
                'charge' => (string) $quote['charge'],
                'label' => $choice['label'],
            ];
        }, $choiceRows)
    ),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
