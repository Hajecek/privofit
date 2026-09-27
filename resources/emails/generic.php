<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($subject ?? 'PRIVOFIT', ENT_QUOTES) ?></title>
</head>
<body style="margin:0;padding:0;background:#0b1210;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b1210;margin:0;padding:0;">
<tr><td align="center" style="padding:28px 16px 40px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#121a17;border:1px solid rgba(232,240,228,.14);border-radius:24px;">
<tr><td style="padding:28px 28px 0;font-family:Figtree,Arial,sans-serif;">
<img src="<?= htmlspecialchars((string) ($logo_url ?? absolute_url('/assets/brand/logo-transparent.png') . '?v=2'), ENT_QUOTES) ?>" alt="PRIVOFIT" width="148" height="28" style="display:block;height:28px;width:auto;border:0;">
</td></tr>
<tr><td style="padding:22px 28px 0;font-family:Figtree,Arial,sans-serif;">
<h1 style="margin:0;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-0.03em;color:#e8f0e4;"><?= htmlspecialchars($subject ?? 'PRIVOFIT', ENT_QUOTES) ?></h1>
</td></tr>
<tr><td style="padding:16px 28px 0;font-family:Figtree,Arial,sans-serif;font-size:16px;line-height:1.55;color:#e8f0e4;">
Ahoj <?= htmlspecialchars($first_name ?? '', ENT_QUOTES) ?>,
</td></tr>
<tr><td style="padding:8px 28px 0;font-family:Figtree,Arial,sans-serif;font-size:16px;line-height:1.6;color:rgba(232,240,228,.78);">
<?= nl2br(htmlspecialchars($body ?? 'Děkujeme, že využíváte PRIVOFIT.', ENT_QUOTES)) ?>
</td></tr>
<?php if (!empty($action_url)): ?>
<tr><td style="padding:26px 28px 0;font-family:Figtree,Arial,sans-serif;">
<a href="<?= htmlspecialchars($action_url, ENT_QUOTES) ?>" style="display:inline-block;background:#c6f21a;color:#101714;font-size:15px;font-weight:700;line-height:1;text-decoration:none;padding:14px 22px;border-radius:999px;">Pokračovat</a>
</td></tr>
<tr><td style="padding:18px 28px 0;font-family:Figtree,Arial,sans-serif;font-size:13px;line-height:1.5;color:rgba(232,240,228,.46);">
Když tlačítko neotevře stránku, zkopíruj adresu:<br>
<a href="<?= htmlspecialchars($action_url, ENT_QUOTES) ?>" style="color:rgba(232,240,228,.62);text-decoration:none;word-break:break-all;"><?= htmlspecialchars($action_url, ENT_QUOTES) ?></a>
</td></tr>
<?php endif; ?>
<tr><td style="height:28px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
