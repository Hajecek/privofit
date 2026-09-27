<?php
$user = $user ?? current_user();
$cspNonce = $cspNonce ?? '';
$title = $title ?? 'PRIVOFIT';
$page = $page ?? 'inner';
$bodyClass = $bodyClass ?? ($page === 'register' ? 'standalone-registration' : '');
$basePath = rtrim((string) app()->request()->basePath(), '/');
$dataBase = $basePath === '' ? '/' : $basePath . '/';
$description = $description ?? 'Objev soukromé fitness PRIVOFIT. Prostor pro tvůj trénink, tvoje tempo a tvoje lepší já.';
$hideChrome = $hideChrome ?? str_contains((string) $bodyClass, 'standalone-');
?>
<!doctype html>
<html lang="cs" data-base="<?= e($dataBase) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0b1210">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>?v=3" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('brand/favicon-32.png')) ?>?v=3">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= e(asset('brand/favicon-48.png')) ?>?v=3">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>?v=3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('/assets/marketing/style.css')) ?>?v=46">
    <link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PRIVOFIT">
</head>
<body class="<?= e($bodyClass) ?>" data-page="<?= e($page) ?>"<?= !empty($mfa) ? ' data-mfa="1"' : '' ?>>
<a class="skip-link" href="#main">Přeskočit na obsah</a>
<div class="scroll-progress" aria-hidden="true"></div>
<header class="header"<?= $hideChrome ? ' hidden' : '' ?>>
    <?php $brandHref = url('/'); $brandLabel = 'PRIVOFIT – úvod'; require dirname(__DIR__) . '/partials/brand-logo.php'; ?>
    <nav aria-label="Hlavní navigace">
        <a href="<?= e(url('/#prostor')) ?>">Proč PRIVOFIT</a>
        <a href="<?= e(url('/#jak-to-funguje')) ?>">Jak to funguje</a>
        <a href="<?= e(url('/#cenik')) ?>">Ceník</a>
        <a href="<?= e(url('/#vstup')) ?>">Vstup do fitka</a>
        <a href="<?= e(url('/#otazky')) ?>">Otázky</a>
        <a href="<?= e(url('/zajem')) ?>">Zájem</a>
    </nav>
    <div class="nav-actions">
        <?php if ($user):
            $avatarName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
            if ($avatarName === '') {
                $avatarName = (string) ($user['username'] ?? 'Profil');
            }
        ?>
            <a href="<?= e(url('/user')) ?>" class="nav-account" title="Otevřít dashboard" aria-label="Otevřít dashboard">
                <img class="nav-avatar" src="<?= e(avatar_url($user)) ?>" alt="<?= e($avatarName) ?>" width="40" height="40">
                <span><?= e((string) ($user['username'] ?? $avatarName)) ?></span>
            </a>
        <?php else: ?>
            <div class="nav-auth">
                <a href="<?= e(url('/prihlaseni')) ?>" class="nav-auth-login">Přihlášení</a>
                <a href="<?= e(url('/registrace')) ?>" class="nav-auth-register">Registrace</a>
            </div>
        <?php endif; ?>
    </div>
    <button class="menu-toggle" aria-label="Otevřít menu" aria-expanded="false">☰</button>
</header>
<?php if (!in_array(($page ?? ''), ['login', 'register'], true)): ?>
<?php if ($msg = flash('success')): ?>
<div class="toast toast-success" role="status" data-toast>
    <span class="toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none"><path d="M20 7 10.2 17 4 11.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>
    <div class="toast-copy">
        <span class="toast-label">Hotovo</span>
        <p><?= e($msg) ?></p>
    </div>
    <button type="button" class="toast-close" data-toast-close aria-label="Zavřít hlášku">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    </button>
    <span class="toast-progress" aria-hidden="true"></span>
</div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
<div class="toast toast-error" role="alert" data-toast>
    <span class="toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none"><path d="M12 8v5.5M12 16.5h.01M12 3.5l9.2 16H2.8L12 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>
    <div class="toast-copy">
        <span class="toast-label">Chyba</span>
        <p><?= e($msg) ?></p>
    </div>
    <button type="button" class="toast-close" data-toast-close aria-label="Zavřít hlášku">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    </button>
    <span class="toast-progress" aria-hidden="true"></span>
</div>
<?php endif; ?>
<?php endif; ?>
<div id="main">
<?= $content ?? '' ?>
</div>
<footer class="wrapper"<?= $hideChrome ? ' hidden' : '' ?>>
    <div class="footer-top">
        <?php $brandHref = url('/'); $brandLabel = 'PRIVOFIT – úvod'; require dirname(__DIR__) . '/partials/brand-logo.php'; ?>
        <p>Tvůj prostor. Tvoje tempo. Tvoje pravidla.</p>
        <a href="#" class="back-top">Zpátky nahoru ↑</a>
    </div>
    <div class="footer-bottom">
        <span>© <span id="year"><?= date('Y') ?></span> PRIVOFIT</span>
        <span>MADE FOR YOUR NEXT MOVE.</span>
    </div>
</footer>
<script nonce="<?= e($cspNonce) ?>" src="<?= e(url('/assets/marketing/app.js')) ?>?v=16"></script>
<script type="module" nonce="<?= e($cspNonce) ?>">
const sceneUrl = <?= json_encode(url('/assets/marketing/scene.js') . '?v=8', JSON_UNESCAPED_SLASHES) ?>;
const decorUrl = <?= json_encode(url('/assets/marketing/decor.js') . '?v=9', JSON_UNESCAPED_SLASHES) ?>;
const phoneUrl = <?= json_encode(url('/assets/marketing/phone.js') . '?v=8', JSON_UNESCAPED_SLASHES) ?>;
const welcomeUrl = <?= json_encode(url('/assets/marketing/welcome.js') . '?v=8', JSON_UNESCAPED_SLASHES) ?>;
const isLoginPage = document.body.dataset.page === "login";
if (document.getElementById("three-stage")) {
  import(sceneUrl).catch(() => {
    const loading = document.getElementById("scene-loading");
    const hint = document.getElementById("scene-hint");
    if (loading) loading.textContent = "3D náhled není dostupný.";
    if (hint) hint.textContent = "Tvůj prostor. Tvoje tempo.";
  });
}
if (document.getElementById("phone-stage")) {
  import(phoneUrl).catch(() => {
    const loading = document.getElementById("phone-loading");
    const status = document.getElementById("phone-status");
    if (loading) loading.textContent = "3D telefon není v tomto prohlížeči dostupný.";
    if (status) status.textContent = "Ukázku můžeš otevřít v prohlížeči s podporou WebGL.";
    document.querySelectorAll(".phone-actions button").forEach((b) => (b.disabled = true));
  });
}
if (document.getElementById("peek-stage") || (document.getElementById("auth-3d") && !isLoginPage)) {
  import(decorUrl).catch(() => {
    const loading = document.getElementById("auth-3d-loading");
    if (loading) loading.textContent = "Tvůj nový začátek.";
  });
}
if (document.getElementById("auth-3d") && isLoginPage) {
  import(welcomeUrl).catch(() => {
    const loading = document.getElementById("auth-3d-loading");
    if (loading) loading.textContent = "Tvůj nový začátek.";
  });
}
</script>
</body>
</html>
