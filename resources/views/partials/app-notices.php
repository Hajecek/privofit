<?php
$error = flash('error');
$ok = flash('success');
require __DIR__ . '/mail-links.php';
?>
<?php if ($error): ?>
    <div class="toast toast-error" role="alert" data-toast>
        <span class="toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"><path d="M12 8v5.5M12 16.5h.01M12 3.5l9.2 16H2.8L12 3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <div class="toast-copy">
            <span class="toast-label">Chyba</span>
            <p><?= e($error) ?></p>
        </div>
        <button type="button" class="toast-close" data-toast-close aria-label="Zavřít hlášku">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
        <span class="toast-progress" aria-hidden="true"></span>
    </div>
<?php endif; ?>
<?php if ($ok): ?>
    <div class="toast toast-success" role="status" data-toast>
        <span class="toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"><path d="M20 7 10.2 17 4 11.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <div class="toast-copy">
            <span class="toast-label">Hotovo</span>
            <p><?= e($ok) ?></p>
        </div>
        <button type="button" class="toast-close" data-toast-close aria-label="Zavřít hlášku">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
        <span class="toast-progress" aria-hidden="true"></span>
    </div>
<?php endif; ?>
