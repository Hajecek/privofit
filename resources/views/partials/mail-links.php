<?php $links = mail_links(); ?>
<?php if ($links !== []): ?>
<div class="mail-pass">
<?php foreach ($links as $link): ?>
    <div class="mail-pass-item">
        <div class="mail-pass-copy">
            <p class="mail-pass-kicker">Odkaz z e-mailu</p>
            <p class="mail-pass-title"><?= e($link['subject']) ?></p>
        </div>
        <a class="button button-small mail-pass-go" href="<?= e($link['url']) ?>">Pokračovat</a>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
