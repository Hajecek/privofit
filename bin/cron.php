<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Env;
use App\Core\Logger;
use App\Services\Cron\CronRunner;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = Application::create();

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('CDN-Cache-Control: no-store');
    $token = (string) Env::get('CRON_TOKEN', '');
    $provided = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$runner = new CronRunner($app->db());
$report = $runner->run();
$line = $runner->format($report);
Logger::append('cron.log', $line);
echo $line;

if (empty($report['ok'])) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit(1);
}

exit(0);
