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
<div class="<?= $mfaLock ? 'mfa-gate-screen' : '' ?>">
    <?php if ($mfaLock): ?>
        <div class="mfa-gate-bar">
            <img class="mfa-gate-logo" src="<?= e(asset('brand/logo-transparent.png')) ?>?v=2" alt="PRIVOFIT" width="180" height="32">
            <form method="post" action="<?= e(url('/odhlaseni')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-secondary button-small" type="submit">Odhlásit</button>
            </form>
        </div>
        <div class="mfa-gate-intro">
            <p class="eyebrow">Povinné zabezpečení</p>
            <h1>Zapni dvoufaktorové ověření</h1>
            <p class="muted">Účet s rolí <?= e($roleName) ?> pokračuje až po tomhle kroku. Návod je celý na téhle obrazovce.</p>
        </div>
        <ol class="mfa-guide">
            <li>
                <strong>1. Otevři aplikaci na kódy</strong>
                <span>Google Authenticator, 1Password, Authy nebo jiná TOTP aplikace.</span>
            </li>
            <li>
                <strong>2. Přidej účet PRIVOFIT</strong>
                <span>Jiným telefonem naskenuj QR. Na tomhle telefonu účet přidej tlačítkem pod kódem, nebo si QR ulož a načti ho z fotek.</span>
            </li>
            <li>
                <strong>3. Potvrď šesti číslicemi</strong>
                <span>Až se účet v aplikaci objeví, zadej aktuální kód. Tím se ověření zapne.</span>
            </li>
        </ol>
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

    <div class="profile-grid mfa-setup">
        <section class="card profile-panel">
            <p class="eyebrow">Krok 1</p>
            <h3>Přidej účet do aplikace</h3>
            <p class="muted">Jiným telefonem naskenuj QR. Na tomhle telefonu použij tlačítka pod kódem.</p>
            <div class="mfa-qr">
                <?php if (!empty($setup['qr_svg'])): ?>
                    <?= $setup['qr_svg'] ?>
                <?php else: ?>
                    <p class="muted">QR se nepodařilo vytvořit<?php if (!empty($setup['qr_error'])): ?>: <?= e((string) $setup['qr_error']) ?><?php endif; ?></p>
                <?php endif; ?>
            </div>
            <div class="mfa-phone">
                <p class="profile-current-label">Stejný telefon</p>
                <p class="muted">Na tomhle telefonu účet přidáš rovnou do aplikace, nebo si QR uložíš a v aplikaci ho načteš z fotek.</p>
                <div class="mfa-phone-actions">
                    <?php if (!empty($setup['otpauth'])): ?>
                        <a class="btn btn-primary" href="<?= e($setup['otpauth']) ?>">Přidat na tomto telefonu</a>
                    <?php endif; ?>
                    <a class="btn btn-secondary" href="<?= e(url('/user/zabezpeceni/mfa/qr?stahnout=1')) ?>" download="privofit-2fa.png" data-qr-save>Uložit QR kód</a>
                </div>
                <p class="mfa-phone-hint">Na telefonu se nabídne uložení do fotek. Na počítači se stáhne obrázek.</p>
            </div>
            <p class="profile-current-label">Nebo zadej klíč ručně</p>
            <p class="mfa-secret"><code><?= e($setup['secret_grouped'] ?? $setup['secret'] ?? '') ?></code></p>
            <button class="btn btn-secondary button-small" type="button" data-copy="<?= e($setup['secret'] ?? '') ?>">Kopírovat klíč</button>
        </section>
        <section class="card profile-panel">
            <p class="eyebrow">Krok 2</p>
            <h3>Potvrď kódem z aplikace</h3>
            <p class="muted">Až se účet v aplikaci objeví, zadej sem aktuální šestimístný kód.</p>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Ověřovací kód</label>
                    <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456">
                </div>
                <button class="btn btn-primary">Aktivovat 2FA</button>
            </form>
        </section>
    </div>
</div>
<?php endif; ?>
