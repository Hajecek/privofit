<?php
ob_start();
$user = $user ?? current_user();
$adminUi = show_admin_nav($user);
$userUi = show_user_nav($user);
if (!function_exists('user_active')) {
    function user_active(string $exact, bool $prefix = false): string {
        $path = app()->request()->path();
        if ($prefix) {
            return str_starts_with($path, $exact) ? 'active' : '';
        }
        return $path === $exact ? 'active' : '';
    }
}
$homeHref = $adminUi ? url('/user/sprava') : url('/user');
$homeLabel = $adminUi ? 'PRIVOFIT – správa' : 'PRIVOFIT – přehled';
?>
<button class="menu-toggle" type="button" aria-label="Otevřít menu" aria-expanded="false" aria-controls="side-menu">☰</button>
<div class="side-backdrop"></div>
<aside class="side-menu" id="side-menu">
    <div class="side-head">
        <?php $brandHref = $homeHref; $brandLabel = $homeLabel; require dirname(__DIR__) . '/partials/brand-logo.php'; ?>
    </div>
    <button class="menu-close" type="button" aria-label="Zavřít menu">✕</button>
    <nav aria-label="<?= $adminUi ? 'Správa' : 'Uživatelská sekce' ?>">
        <?php if ($userUi): ?>
        <a class="side-link <?= user_active('/user') ?>" href="<?= e(url('/user')) ?>" title="Domů">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 11 12 4l8 7v9H4z"/></svg>
            <span>Domů</span>
        </a>
        <div class="side-group<?= user_active('/user/rezervace', true) !== '' || user_active('/user/moje-rezervace', true) !== '' ? ' is-open' : '' ?>">
            <a class="side-link <?= user_active('/user/rezervace', true) ?>" href="<?= e(url('/user/rezervace')) ?>" title="Rezervace">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                <span>Rezervace</span>
            </a>
            <div class="side-sub">
                <a class="side-sublink <?= user_active('/user/moje-rezervace', true) ?>" href="<?= e(url('/user/moje-rezervace')) ?>" title="Moje rezervace">Moje</a>
            </div>
        </div>
        <a class="side-link <?= user_active('/user/vstup', true) ?>" href="<?= e(url('/user/vstup')) ?>" title="Vstup">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            <span>Vstup</span>
        </a>
        <a class="side-link <?= user_active('/user/clenstvi', true) ?>" href="<?= e(url('/user/clenstvi')) ?>" title="Členství">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 1.5"/></svg>
            <span>Členství</span>
        </a>
        <?php endif; ?>

        <?php if ($adminUi): ?>
            <a class="side-link <?= user_active('/user/sprava') ?>" href="<?= e(url('/user/sprava')) ?>" title="Přehled">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 11 12 4l8 7v9H4z"/></svg>
                <span>Přehled</span>
            </a>
            <a class="side-link <?= user_active('/user/sprava/rezervace', true) ?>" href="<?= e(url('/user/sprava/rezervace')) ?>" title="Rezervace">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                <span>Rezervace</span>
            </a>
            <a class="side-link <?= user_active('/user/sprava/zakaznici', true) ?>" href="<?= e(url('/user/sprava/zakaznici')) ?>" title="Zákazníci">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16" cy="9" r="2.5"/><path d="M3 19c1.2-3.2 10.8-3.2 12 0M14 19c.4-2 5.6-2.4 7 0"/></svg>
                <span>Zákazníci</span>
            </a>
            <a class="side-link <?= user_active('/user/studio') ?>" href="<?= e(url('/user/studio')) ?>" title="Studia">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 10 12 4l8 6v9H4z"/><path d="M9 19v-6h6v6"/></svg>
                <span>Studia</span>
            </a>
            <a class="side-link <?= user_active('/user/studio/ceny', true) ?>" href="<?= e(url('/user/studio/ceny')) ?>" title="Ceny">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 3v18M16 7.5c0-1.8-1.8-3-4-3s-4 1.2-4 3 1.8 2.7 4 3 4 1.2 4 3-1.8 3-4 3-4-1.2-4-3"/></svg>
                <span>Ceny</span>
            </a>
            <a class="side-link <?= user_active('/user/studio/doba', true) ?>" href="<?= e(url('/user/studio/doba')) ?>" title="Otevírací doba">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 1.5"/></svg>
                <span>Otevírací doba</span>
            </a>
            <a class="side-link <?= user_active('/user/sprava/tarify', true) ?>" href="<?= e(url('/user/sprava/tarify')) ?>" title="Tarify">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M5 7h14v10H5z"/><path d="M8 7V5h8v2M9 12h6"/></svg>
                <span>Tarify</span>
            </a>
            <a class="side-link <?= user_active('/user/sprava/trzby', true) ?>" href="<?= e(url('/user/sprava/trzby')) ?>" title="Tržby">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 19h16M7 16V9M12 16V5M17 16v-5"/></svg>
                <span>Tržby</span>
            </a>
            <?php
            $doorNavRow = app()->db()->fetch('SELECT id, last_known_state, last_known_door_state FROM doors WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
            $doorNavLock = strtolower((string) ($doorNavRow['last_known_state'] ?? ''));
            $doorNavSensor = strtolower((string) ($doorNavRow['last_known_door_state'] ?? ''));
            $doorNavOpen = in_array($doorNavLock, ['unlocked', 'unlatched', 'unlocking', 'unlatching', 'unlocked_lock_n_go', 'odkleceno'], true)
                || in_array($doorNavSensor, ['opened', 'open'], true);
            ?>
            <a class="side-link <?= user_active('/user/sprava/vstup', true) ?> <?= $doorNavOpen ? 'is-door-open' : 'is-door-closed' ?>" href="<?= e(url('/user/sprava/vstup')) ?>" title="<?= $doorNavOpen ? 'Dveře, otevřeno' : 'Dveře, zavřeno' ?>" data-door-nav="<?= e(url('/user/sprava/vstup/stav')) ?>" data-door-nav-id="<?= (int) ($doorNavRow['id'] ?? 0) ?>">
                <svg class="door-nav-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <g data-door-lock-closed <?= $doorNavOpen ? 'hidden' : '' ?>><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></g>
                    <g data-door-lock-open <?= $doorNavOpen ? '' : 'hidden' ?>><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.5-2"/></g>
                </svg>
                <span>Dveře</span>
                <svg class="door-nav-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <g data-door-mark-closed <?= $doorNavOpen ? 'hidden' : '' ?>><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></g>
                    <g data-door-mark-open <?= $doorNavOpen ? '' : 'hidden' ?>><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.5-2"/></g>
                </svg>
            </a>
            <a class="side-link <?= user_active('/user/sprava/zajem', true) ?>" href="<?= e(url('/user/sprava/zajem')) ?>" title="Zájem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                <span>Zájem</span>
            </a>
            <a class="side-link <?= user_active('/user/sprava/nastaveni', true) ?>" href="<?= e(url('/user/sprava/nastaveni')) ?>" title="Nastavení">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 4v2M12 18v2M4 12h2M18 12h2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4"/></svg>
                <span>Nastavení</span>
            </a>
        <?php endif; ?>
    </nav>
    <?php require dirname(__DIR__) . '/partials/side-profile.php'; ?>
</aside>
<div class="app-main" id="main">
    <?php require dirname(__DIR__) . '/partials/app-notices.php'; ?>
    <?= $content ?? '' ?>
</div>
<?php
$inner = ob_get_clean();
echo \App\Core\View::renderPartial('layouts/base', array_merge(get_defined_vars(), ['content' => $inner, 'bodyClass' => 'user-app']));
