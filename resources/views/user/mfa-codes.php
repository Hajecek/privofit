<?php $mfaLock = !empty($mfaLock); ?>
<div class="mfa-stage<?= $mfaLock ? ' is-lock' : '' ?>">
    <?php if ($mfaLock): ?>
        <div class="mfa-gate-bar">
            <img class="mfa-gate-logo" src="<?= e(asset('brand/logo-transparent.png')) ?>?v=2" alt="PRIVOFIT" width="180" height="32">
            <form method="post" action="<?= e(url('/odhlaseni')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-secondary button-small" type="submit">Odhlásit</button>
            </form>
        </div>
    <?php endif; ?>

    <section class="mfa-simple mfa-simple-codes">
        <header class="mfa-intro">
            <p class="eyebrow">Zabezpečení</p>
            <h1>Ulož záložní kódy</h1>
            <p class="muted">Každý jde použít jen jednou. Uvidíš je jen teď.</p>
        </header>
        <?php if ($codes): ?>
            <ul class="mfa-codes">
                <?php foreach ($codes as $code): ?>
                    <li><code><?= e($code) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <div class="mfa-actions">
                <button class="btn btn-secondary" type="button" data-copy="<?= e(implode("\n", $codes)) ?>">Kopírovat všechny</button>
                <?php if ($mfaLock): ?>
                    <a class="btn btn-primary" href="<?= e(url($continueTo ?? '/user/sprava')) ?>">Pokračovat</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="muted mfa-codes-empty">Záložní kódy se zobrazují jen jednou, hned po zapnutí 2FA.</p>
            <a class="btn btn-secondary" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
        <?php endif; ?>
    </section>
</div>
