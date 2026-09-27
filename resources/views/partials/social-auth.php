<?php
$googleReady = \App\Services\Auth\GoogleOAuth::configured();
$appleReady = \App\Services\Auth\AppleOAuth::configured();
?>
<div id="social-auth" class="social-auth">
    <?php if ($googleReady): ?>
        <a href="<?= e(url('/prihlaseni/google')) ?>" data-provider="Google" aria-label="Pokračovat s Google">
            <span class="provider-icon google-icon" aria-hidden="true">G</span>
            Google
        </a>
    <?php else: ?>
        <button type="button" data-provider="Google" aria-label="Pokračovat s Google">
            <span class="provider-icon google-icon" aria-hidden="true">G</span>
            Google
        </button>
    <?php endif; ?>
    <?php if ($appleReady): ?>
        <a href="<?= e(url('/prihlaseni/apple')) ?>" data-provider="Apple" aria-label="Pokračovat s Apple">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.5 3.1c.7-.8 1.1-1.8 1-2.8-1 .1-2.1.7-2.8 1.5-.6.7-1.1 1.7-1 2.7 1 .1 2.1-.5 2.8-1.4ZM20.1 17.4c-.5 1.2-.8 1.7-1.4 2.7-.8 1.2-1.9 2.7-3.3 2.7-1.2 0-1.5-.8-3.2-.8s-2 .8-3.3.8c-1.4.1-2.5-1.4-3.3-2.6C3.3 16.9 2.5 12 4 9.4c1.1-1.8 2.8-2.9 4.5-2.9 1.4 0 2.3.8 3.5.8 1.1 0 1.8-.8 3.5-.8 1.4 0 2.9.8 3.9 2.1-3.4 1.9-2.9 6.9.7 8.8Z"/></svg>
            Apple
        </a>
    <?php else: ?>
        <button type="button" data-provider="Apple" aria-label="Pokračovat s Apple">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.5 3.1c.7-.8 1.1-1.8 1-2.8-1 .1-2.1.7-2.8 1.5-.6.7-1.1 1.7-1 2.7 1 .1 2.1-.5 2.8-1.4ZM20.1 17.4c-.5 1.2-.8 1.7-1.4 2.7-.8 1.2-1.9 2.7-3.3 2.7-1.2 0-1.5-.8-3.2-.8s-2 .8-3.3.8c-1.4.1-2.5-1.4-3.3-2.6C3.3 16.9 2.5 12 4 9.4c1.1-1.8 2.8-2.9 4.5-2.9 1.4 0 2.3.8 3.5.8 1.1 0 1.8-.8 3.5-.8 1.4 0 2.9.8 3.9 2.1-3.4 1.9-2.9 6.9.7 8.8Z"/></svg>
            Apple
        </button>
    <?php endif; ?>
</div>
<?php if ($googleReady || $appleReady): ?>
<p class="social-note">Pokračováním souhlasíš s <a href="<?= e(url('/dokument/obchodni-podminky')) ?>">podmínkami</a> a <a href="<?= e(url('/dokument/ochrana-udaju')) ?>">zásadami ochrany údajů</a>.</p>
<?php endif; ?>
<p id="social-status" class="social-status" role="status" hidden></p>
<div id="auth-divider" class="auth-divider"><span>nebo e-mailem</span></div>
