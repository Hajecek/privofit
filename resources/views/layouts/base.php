<?php
$user = $user ?? current_user();
$cspNonce = $cspNonce ?? '';
$title = $title ?? 'PRIVOFIT';
$bodyClass = $bodyClass ?? '';
?><!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1210">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · PRIVOFIT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('user/style.css')) ?>?v=140">
    <link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>?v=3" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('brand/favicon-32.png')) ?>?v=3">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= e(asset('brand/favicon-48.png')) ?>?v=3">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>?v=3">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PRIVOFIT">
</head>
<body class="<?= e($bodyClass) ?>"<?php if (!empty($user)): ?> data-presence="<?= e(url('/user/pritomnost')) ?>" data-signed-out="<?= e(url('/odhlaseno')) ?>"<?php endif; ?>>
<a class="skip-link" href="#main">Přeskočit na obsah</a>
<?= $content ?? '' ?>
<script nonce="<?= e($cspNonce) ?>" src="<?= e(asset('js/app.js')) ?>?v=27"></script>
<?php foreach (($pageScripts ?? []) as $src): ?>
<script nonce="<?= e($cspNonce) ?>" src="<?= e(asset($src)) ?>?v=69"></script>
<?php endforeach; ?>
<script nonce="<?= e($cspNonce) ?>">
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= e(url('/service-worker.js')) ?>').catch(() => {});
}
</script>
</body>
</html>
