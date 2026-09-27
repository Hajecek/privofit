<?php
$status = is_array($status ?? null) ? $status : [];
$doors = is_array($doors ?? null) ? $doors : [];
$logs = is_array($logs ?? null) ? $logs : [];
$configured = !empty($status['configured']);
$testMode = !empty($status['test_mode']);
$liveOnline = !empty($status['online']);
$liveLock = (string) ($status['lock_state'] ?? '');
$liveDoor = (string) ($status['door_state'] ?? '');
$liveBattery = $status['battery_percent'] ?? null;
$liveCritical = !empty($status['battery_critical']);

$primaryId = 0;
foreach ($doors as $door) {
    if (!empty($door['is_active'])) {
        $primaryId = (int) ($door['id'] ?? 0);
        break;
    }
}

$openLocks = ['unlocked', 'unlatched', 'unlocking', 'unlatching', 'unlocked_lock_n_go', 'odkleceno'];
$openSensors = ['opened', 'open'];

$reasonLabels = [
    'admin_open' => 'Správce otevřel',
    'admin_close' => 'Správce zavřel',
    'unverified' => 'Neověřený e-mail',
    'inactive' => 'Neaktivní účet',
    'no_reservation' => 'Bez rezervace',
    'no_door' => 'Chybí dveře',
    'no_permission' => 'Bez oprávnění',
    'rate_limited' => 'Moc pokusů',
    'door_busy' => 'Zámek je zaneprázdněný',
    'not_configured' => 'Není nastaveno',
    'connection' => 'Spojení selhalo',
    'http' => 'Zámek odmítl příkaz',
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SPRÁVA</p>
        <h1>Dveře</h1>
        <p class="muted">Stav zámku je v přepínači vedle názvu. Stránka se při změně neobnovuje.</p>
    </div>
</div>

<?php if ($doors === []): ?>
<section class="door-panel is-closed">
    <div class="door-panel-top">
        <div>
            <p class="eyebrow">ZÁMEK</p>
            <h2>Žádné dveře</h2>
            <p class="door-panel-lead">Až bude v systému aktivní zámek, objeví se tady přepínač.</p>
        </div>
    </div>
</section>
<?php else: ?>
<div class="door-board">
    <?php foreach ($doors as $door): ?>
        <?php
        $id = (int) ($door['id'] ?? 0);
        $active = !empty($door['is_active']);
        $live = $configured && $id === $primaryId;
        $lock = strtolower($live ? $liveLock : (string) ($door['last_known_state'] ?? ''));
        $sensor = strtolower($live ? $liveDoor : (string) ($door['last_known_door_state'] ?? ''));
        $isOpen = in_array($lock, $openLocks, true) || in_array($sensor, $openSensors, true);
        $batteryRaw = $live ? $liveBattery : ($door['last_battery_percent'] ?? null);
        $batteryNum = ($batteryRaw === null || $batteryRaw === '') ? null : max(0, min(100, (int) $batteryRaw));
        $critical = $live ? $liveCritical : !empty($door['battery_critical']);
        $batteryLow = $critical || ($batteryNum !== null && $batteryNum <= 15);
        $online = $live ? $liveOnline : !empty($door['last_online_at']);
        $provider = strtoupper((string) ($door['provider'] ?? ($status['provider'] ?? 'n/a')));
        ?>
        <section class="door-panel<?= $isOpen ? ' is-open' : ' is-closed' ?><?= $active ? '' : ' is-off' ?>" data-door-id="<?= $id ?>" data-door-status="<?= e(url('/user/sprava/vstup/stav')) ?>">
            <div class="door-panel-top">
                <div>
                    <p class="eyebrow"><?= e($provider) ?></p>
                    <h2><?= e((string) ($door['name'] ?? 'Dveře')) ?></h2>
                </div>
                <form method="post" action="<?= e(url('/user/sprava/vstup/stav')) ?>" class="door-switch-form" data-door-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="door_id" value="<?= $id ?>">
                    <div class="door-seg" role="radiogroup" aria-label="<?= e('Otevřít nebo zavřít ' . (string) ($door['name'] ?? 'dveře')) ?>">
                        <label class="door-seg-opt">
                            <input type="radio" name="state" value="close" data-door-input <?= $isOpen ? '' : 'checked' ?> <?= $active ? '' : 'disabled' ?>>
                            <span>Zavřeno</span>
                        </label>
                        <label class="door-seg-opt">
                            <input type="radio" name="state" value="open" data-door-input <?= $isOpen ? 'checked' : '' ?> <?= $active ? '' : 'disabled' ?>>
                            <span>Otevřeno</span>
                        </label>
                    </div>
                    <p class="door-switch-note" data-door-note hidden></p>
                    <button class="btn btn-secondary door-switch-save" type="submit">Potvrdit</button>
                </form>
            </div>
            <div class="door-stats">
                <article class="door-stat<?= $active && $online ? ' is-on' : ' is-off' ?>" data-door-online>
                    <span class="door-stat-k">Spojení</span>
                    <strong><i class="door-dot" aria-hidden="true"></i><span data-door-online-label><?= $active && $online ? 'Online' : 'Offline' ?></span></strong>
                    <span class="door-stat-sub" data-door-online-sub><?= $active && $online ? 'Zámek odpovídá' : 'Zámek teď neodpovídá' ?></span>
                </article>
                <article class="door-stat<?= $batteryLow ? ' is-low' : '' ?>" data-door-battery-stat>
                    <span class="door-stat-k">Baterie</span>
                    <strong>
                        <span class="door-bat<?= $batteryLow ? ' is-low' : '' ?>" data-door-bat aria-hidden="true"><span class="door-bat-fill" data-door-fill style="width: <?= $batteryNum ?? 0 ?>%"></span></span>
                        <span data-door-battery><?= $batteryNum === null ? '—' : e((string) $batteryNum) . '%' ?></span>
                    </strong>
                    <span class="door-stat-sub" data-door-battery-sub><?= $batteryNum === null ? 'Stav není známý' : ($batteryLow ? 'Dochází, vyměň článek' : 'Nabití je v pořádku') ?></span>
                </article>
                <article class="door-stat door-stat-mode<?= $testMode ? ' is-test' : ' is-live' ?>">
                    <span class="door-stat-k">Režim</span>
                    <strong><?= $testMode ? 'Test' : 'Ostrý' ?></strong>
                    <span class="door-stat-sub"><?= $testMode ? 'Fyzické dveře se nepohnou' : 'Příkaz jde rovnou na zámek' ?></span>
                </article>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="door-logs" id="logy">
    <div class="door-logs-head">
        <div>
            <p class="eyebrow">HISTORIE</p>
            <h2>Logy vstupů</h2>
            <p class="muted">Samostatný přehled posledních <?= count($logs) ?> pokusů. Na ovládání dveří nemá vliv.</p>
        </div>
    </div>
    <div class="card door-logs-card">
        <?php if ($logs === []): ?>
            <p class="door-admin-empty muted">Zatím žádné záznamy.</p>
        <?php else: ?>
            <div class="table-wrap door-admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>Čas</th>
                            <th>Kdo</th>
                            <th>Výsledek</th>
                            <th>Příkaz</th>
                            <th>Poznámka</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $authOk = ($log['authorization_result'] ?? '') === 'granted';
                        $cmd = (string) ($log['command_result'] ?? 'not_sent');
                        $cmdClass = match ($cmd) {
                            'accepted' => 'badge-ok',
                            'failed', 'timeout', 'conflict' => 'badge-bad',
                            default => 'badge-muted',
                        };
                        $cmdLabel = match ($cmd) {
                            'accepted' => 'Přijat',
                            'failed' => 'Selhal',
                            'timeout' => 'Timeout',
                            'conflict' => 'Konflikt',
                            'not_sent' => 'Neodeslán',
                            default => $cmd,
                        };
                        $who = trim((string) (($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')));
                        if ($who === '') {
                            $who = (string) ($log['username'] ?? '—');
                        }
                        $reasonKey = (string) ($log['denial_reason'] ?? '');
                        $reason = $reasonLabels[$reasonKey] ?? ($reasonKey !== '' ? $reasonKey : '—');
                        ?>
                        <tr>
                            <td><?= e(format_datetime((string) ($log['created_at'] ?? ''))) ?></td>
                            <td><?= e($who) ?></td>
                            <td><span class="badge <?= $authOk ? 'badge-ok' : 'badge-bad' ?>"><?= $authOk ? 'Povoleno' : 'Zamítnuto' ?></span></td>
                            <td><span class="badge <?= e($cmdClass) ?>"><?= e($cmdLabel) ?></span></td>
                            <td class="muted"><?= e($reason) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
