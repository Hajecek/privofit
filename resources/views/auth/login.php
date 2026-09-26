<?php $mfa = !empty($mfa); ?>
<main id="auth">
    <section class="auth-layout wrapper">
        <div class="auth-story">
            <a class="back" href="<?= e(url('/')) ?>">← Zpět na úvod</a>
            <div class="eyebrow">TVŮJ PROSTOR NA TEBE ČEKÁ</div>
            <h1>Tvůj klíč.<br><span>Tvůj prostor.</span></h1>
            <p id="auth-story-copy">Vítej zpátky. Odemkni si čas jen pro sebe.</p>
            <div id="auth-3d" tabindex="0" role="button" aria-label="3D členský klíč PRIVOFIT. Tažením nebo šipkami ho otočíš. Kliknutím ho obrátíš.">
                <p id="auth-3d-loading">Tvůj nový začátek.</p>
            </div>
            <p class="auth-card-caption">Tvůj přístup k vlastnímu tempu. Tažením otoč klíč.</p>
        </div>
        <div class="auth-card">
            <div class="auth-switch" hidden>
                <a href="<?= e(url('/prihlaseni')) ?>" id="login-tab">Přihlášení</a>
                <a href="<?= e(url('/registrace')) ?>" id="register-tab">Registrace</a>
            </div>
            <div id="registration-progress" hidden>
                <div class="step-counter">
                    <span id="step-count">KROK 01 / 03</span>
                    <span id="step-name">O tobě</span>
                </div>
                <div class="step-bars" aria-hidden="true"><i class="active"></i><i></i><i></i></div>
            </div>
            <h2 id="auth-title" tabindex="-1"><?= $mfa ? 'Ověření přihlášení' : 'Pojďme na to.' ?></h2>
            <p id="auth-subtitle"><?= $mfa ? 'Zadej kód z autentizační aplikace. Na tomto prohlížeči pak ' . (int) config('security.session.mfa_trust_days', 30) . ' dní stačí heslo.' : 'Přihlas se do svého prostoru.' ?></p>
            <?php require dirname(__DIR__) . '/partials/form-alert.php'; ?>
            <?php if (!$mfa): ?>
                <?php require dirname(__DIR__) . '/partials/social-auth.php'; ?>
            <?php endif; ?>
            <form id="auth-form" method="post" action="<?= e(url('/prihlaseni')) ?>" novalidate>
                <?= csrf_field() ?>
                <fieldset id="identity-step" hidden>
                    <legend class="sr-only">O tobě</legend>
                    <div class="field-row">
                        <label>Jméno<input name="first_name" maxlength="80"></label>
                        <label>Příjmení<input name="last_name" maxlength="80"></label>
                    </div>
                    <label>Uživatelské jméno<input name="username" maxlength="40"></label>
                </fieldset>
                <fieldset id="security-step">
                    <legend class="sr-only">Přihlašovací údaje</legend>
                    <label>
                        <span id="login-identifier-label">E-mail nebo uživatelské jméno</span>
                        <input type="text" name="identifier" autocomplete="username" placeholder="E-mail nebo uživatelské jméno" required maxlength="190" spellcheck="false" autocapitalize="none" value="<?= e($email ?? old('identifier') ?: old('email')) ?>">
                    </label>
                    <?php if (!$mfa): ?>
                        <label>Heslo
                            <div class="password-field">
                                <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Tvoje heslo" required>
                                <button type="button" id="show-password" aria-label="Zobrazit heslo">Zobrazit</button>
                            </div>
                            <small id="password-hint" hidden>Alespoň 8 znaků.</small>
                        </label>
                        <label class="legal-check"><input type="checkbox" name="remember" value="1"><span>Zapamatovat přihlášení</span></label>
                    <?php else: ?>
                        <input type="hidden" name="password" value="">
                        <input type="hidden" name="remember" value="<?= !empty($remember) ? '1' : '' ?>">
                        <label>Kód z aplikace
                            <input name="totp" inputmode="numeric" autocomplete="off" required placeholder="123456" maxlength="8">
                        </label>
                        <input id="password" type="hidden" value="">
                        <small id="password-hint" hidden></small>
                    <?php endif; ?>
                    <div id="confirm-field" hidden>
                        <label>Heslo ještě jednou<input type="password" id="confirm-password" minlength="8"></label>
                    </div>
                </fieldset>
                <fieldset id="review-step" hidden>
                    <legend class="sr-only">Kontrola údajů</legend>
                    <div class="review-profile">
                        <span id="review-avatar">P</span>
                        <div><strong id="review-name"></strong><small id="review-username"></small></div>
                    </div>
                    <div class="review-row"><span>E-mail</span><strong id="review-email"></strong></div>
                    <div class="review-row"><span>Heslo</span><strong>Vyplněno ✓</strong></div>
                </fieldset>
                <div class="form-actions">
                    <button type="button" id="step-back" hidden>← Zpět</button>
                    <button class="button submit-button" type="submit" id="submit-button"><?= $mfa ? 'Ověřit' : 'Přihlásit se' ?> <span>↗</span></button>
                </div>
                <p id="form-status" class="form-status" role="status" tabindex="-1" hidden></p>
            </form>
            <p class="auth-bottom" id="auth-bottom"<?= $mfa ? ' hidden' : '' ?>>Ještě nemáš účet? <a href="<?= e(url('/registrace')) ?>">Začni tady</a></p>
            <p class="auth-forgot"><a href="<?= e(url('/zapomenute-heslo')) ?>">Zapomenuté heslo</a></p>
        </div>
    </section>
</main>
