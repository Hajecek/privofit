<?php
$entriesLabel = static function (array $plan): string {
    if ($plan['entries'] === null && ($plan['type'] ?? '') === 'monthly') {
        return 'Neomezené vstupy';
    }
    $count = (int) ($plan['entries'] ?? 0);
    if ($count === 1) {
        return '1 vstup';
    }
    if ($count > 1 && $count < 5) {
        return $count . ' vstupy';
    }
    return $count . ' vstupů';
};
$daysLabel = static function (array $plan): string {
    $days = (int) ($plan['duration_days'] ?? 0);
    if ($days <= 0) {
        return 'Bez časového omezení';
    }
    if ($days === 1) {
        return 'Platí 1 den';
    }
    if ($days < 5) {
        return 'Platí ' . $days . ' dny';
    }
    return 'Platí ' . $days . ' dní';
};
?>
<div class="page-head">
    <div>
        <p class="eyebrow">TVŮJ TARIF</p>
        <h1>Členství</h1>
        <p class="muted">Vyber plán a zaplať. Pak si v rezervaci vybereš jen den. Platit znovu nemusíš, odečte se vstup.</p>
    </div>
</div>

<?php if ($current): ?>
<section class="member-hero card">
    <div>
        <p class="eyebrow">Aktivní tarif</p>
        <h2><?= e($current['plan_name']) ?></h2>
        <p class="muted">
            <?php if ($current['entries_remaining'] === null): ?>
                Vstupy jsou neomezené<?= $current['ends_at'] ? ' do ' . e(format_datetime($current['ends_at'], 'd. m. Y')) : '' ?>.
            <?php else: ?>
                Zbývá <?= (int) $current['entries_remaining'] ?> <?= (int) $current['entries_remaining'] === 1 ? 'vstup' : ((int) $current['entries_remaining'] < 5 ? 'vstupy' : 'vstupů') ?><?= $current['ends_at'] ? ' do ' . e(format_datetime($current['ends_at'], 'd. m. Y')) : '' ?>.
            <?php endif; ?>
        </p>
    </div>
    <a class="button" href="<?= e(url('/user/rezervace')) ?>">Vybrat den <span>↗</span></a>
</section>
<?php endif; ?>

<div class="plan-grid">
    <?php foreach ($plans as $plan):
        if (($plan['type'] ?? '') === 'credit' || ($plan['type'] ?? '') === 'lifetime') {
            continue;
        }
        $featured = ($plan['type'] ?? '') === 'monthly';
    ?>
        <article class="plan-card card<?= $featured ? ' is-featured' : '' ?>">
            <?php if ($featured): ?><p class="plan-flag">Neomezeně</p><?php endif; ?>
            <h2><?= e($plan['name']) ?></h2>
            <p class="plan-entries"><?= e($entriesLabel($plan)) ?></p>
            <p class="plan-price"><?= e(money_format_czk($plan['price'])) ?></p>
            <?php
            $cardFee = \App\Services\Billing\StripeFee::cover((string) $plan['price']);
            if ((float) $cardFee['fee'] > 0):
            ?>
                <p class="muted">Evropská karta <?= e(number_format((float) $cardFee['charge'], 2, ',', ' ')) ?> Kč, z toho poplatek <?= e(number_format((float) $cardFee['fee'], 2, ',', ' ')) ?> Kč. Britská a zahraniční karta, včetně Linku, se přepočítá před zaplacením.</p>
            <?php endif; ?>
            <ul>
                <li><?= e($daysLabel($plan)) ?></li>
                <li>Rezervace dne bez další platby</li>
                <li>Jeden termín = jeden vstup</li>
            </ul>
            <form method="post" action="<?= e(url('/user/clenstvi')) ?>" data-plan-form>
                <?= csrf_field() ?>
                <input type="hidden" name="plan" value="<?= e($plan['public_id']) ?>">
                <button class="button" type="submit">Vybrat a zaplatit</button>
            </form>
        </article>
    <?php endforeach; ?>
</div>
