<?php
$enabled = !empty($enabled);
$mfaRequired = !empty($mfaRequired);
$mfaLock = !empty($mfaLock) && !$enabled;
$setup = $setup ?? null;
$errors = $errors ?? [];
$recoveryLeft = (int) ($recoveryLeft ?? 0);
$roleName = role_label((string) ($user['role'] ?? 'admin'));
?>
<?php if ($enabled): ?>
<div class="page-head">
    <div>
        <p class="eyebrow">ZABEZPEČENÍ</p>
        <h1>Dvoufaktorové ověření</h1>
        <p class="muted">Účet je chráněný kódem z autentizační aplikace.</p>
    </div>
    <a class="btn btn-secondary button-small" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
</div>
<section class="card profile-panel">
    <div class="profile-panel-head">
        <div>
            <p class="eyebrow">Stav</p>
            <h3>2FA je zapnuté</h3>
        </div>
        <span class="badge badge-ok">Aktivní</span>
    </div>
    <p class="muted">Při každém přihlášení po hesle zadáš šestimístný kód z aplikace, nebo jednorázový záložní kód.</p>
    <p class="profile-current">Zbývá záložních kódů: <?= $recoveryLeft ?></p>
    <?php if ($recoveryLeft === 0): ?>
        <p class="profile-note">Záložní kódy už nemáš. Pokud ztratíš telefon, bude potřeba obnovit přístup přes podporu.</p>
    <?php endif; ?>
    <?php if ($mfaRequired): ?>
        <p class="profile-note">Pro účet <?= e($roleName) ?> nelze dvoufaktorové ověření vypnout.</p>
    <?php else: ?>
        <div class="profile-divider"></div>
        <form method="post" action="<?= e(url('/user/zabezpeceni/mfa/vypnout')) ?>">
            <?= csrf_field() ?>
            <h3>Vypnout 2FA</h3>
            <p class="muted">Pro potvrzení zadej současné heslo.</p>
            <div class="field">
                <label>Současné heslo</label>
                <input type="password" name="current_password" required autocomplete="current-password">
                <span class="field-error"><?= e($errors['current_password'][0] ?? '') ?></span>
            </div>
            <button class="btn btn-danger">Vypnout dvoufaktorové ověření</button>
        </form>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="mfa-stage<?= $mfaLock ? ' is-lock' : '' ?>">
    <?php if ($mfaLock): ?>
        <div class="mfa-gate-bar">
            <img class="mfa-gate-logo" src="<?= e(asset('brand/logo-transparent.png')) ?>?v=2" alt="PRIVOFIT" width="180" height="32">
            <form method="post" action="<?= e(url('/odhlaseni')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-secondary button-small" type="submit">Odhlásit</button>
            </form>
        </div>
    <?php else: ?>
        <div class="page-head">
            <div>
                <p class="eyebrow">ZABEZPEČENÍ</p>
                <h1>Dvoufaktorové ověření</h1>
                <p class="muted">Přidej druhý krok k přihlášení. Heslo samotné pak nestačí.</p>
            </div>
            <a class="btn btn-secondary button-small" href="<?= e(url('/user/profil')) ?>">Zpět do profilu</a>
        </div>
    <?php endif; ?>

    <section class="mfa-simple">
        <?php if ($mfaLock): ?>
            <header class="mfa-intro">
                <p class="eyebrow">Povinné zabezpečení</p>
                <h1>Zapni ověření</h1>
                <p class="muted">Naskenuj QR v aplikaci na kódy.</p>
            </header>
        <?php endif; ?>
        <div class="mfa-qr">
            <?php if (!empty($setup['qr_svg'])): ?>
                <?= $setup['qr_svg'] ?>
            <?php else: ?>
                <p class="muted">QR se nepodařilo vytvořit<?php if (!empty($setup['qr_error'])): ?>: <?= e((string) $setup['qr_error']) ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <div class="mfa-choices">
            <?php if (!empty($setup['otpauth'])): ?>
                <a class="btn btn-secondary btn-block" href="<?= e($setup['otpauth']) ?>">Přidat na tomto telefonu</a>
            <?php endif; ?>
            <p class="mfa-choice-links">
                <a href="<?= e(url('/user/zabezpeceni/mfa/qr?stahnout=1')) ?>" download="privofit-2fa.png" data-qr-save>Uložit QR</a>
                <button type="button" data-copy="<?= e($setup['secret'] ?? '') ?>">Kopírovat klíč</button>
            </p>
            <p class="mfa-key"><code><?= e($setup['secret_grouped'] ?? $setup['secret'] ?? '') ?></code></p>
        </div>
        <form class="mfa-confirm" method="post">
            <?= csrf_field() ?>
            <label for="mfa-code">Kód z aplikace</label>
            <input id="mfa-code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="000000">
            <button class="btn btn-primary btn-block" type="submit">Aktivovat</button>
        </form>
    </section>
</div>
<?php endif; ?>
