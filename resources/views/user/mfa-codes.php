<?php $mfaLock = !empty($mfaLock); ?>
<?php if ($mfaLock): ?>
<div class="mfa-gate-screen">
    <div class="mfa-gate-bar">
        <img class="mfa-gate-logo" src="<?= e(asset('brand/logo-transparent.png')) ?>?v=2" alt="PRIVOFIT" width="180" height="32">
        <form method="post" action="<?= e(url('/odhlaseni')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-secondary button-small" type="submit">Odhlásit</button>
        </form>
    </div>
    <div class="mfa-gate-intro">
        <p class="eyebrow">ZABEZPEČENÍ</p>
        <h1>Ulož záložní kódy</h1>
        <p class="muted">Každý kód jde použít jen jednou, místo kódu z aplikace. Tahle obrazovka je jediná, kde je uvidíš.</p>
    </div>
<?php else: ?>
<div class="page-head">
    <div>
        <p class="eyebrow">ZABEZPEČENÍ</p>
        <h1>Záložní kódy</h1>
        <p class="muted">Ulož si je na bezpečné místo. Každý kód jde použít jen jednou, místo kódu z aplikace.</p>
    </div>
</div>
<?php endif; ?>

<section class="card profile-panel">
    <?php if ($codes): ?>
        <ul class="recovery-grid">
            <?php foreach ($codes as $code): ?>
                <li><code><?= e($code) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <div class="profile-hero-actions">
            <button class="btn btn-secondary" type="button" data-copy="<?= e(implode("\n", $codes)) ?>">Kopírovat všechny</button>
            <?php if ($mfaLock): ?>
                <a class="btn btn-primary" href="<?= e(url($continueTo ?? '/user/sprava')) ?>">Pokračovat</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted">Záložní kódy se zobrazují jen jednou, hned po zapnutí 2FA.</p>
        <a class="btn btn-secondary" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
    <?php endif; ?>
</section>
<?php if ($mfaLock): ?>
</div>
<?php endif; ?>
