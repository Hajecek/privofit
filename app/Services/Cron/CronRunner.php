<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Cron\Jobs\DailyRevenueJob;
use App\Services\Cron\Jobs\DispatchNotificationsJob;
use App\Services\Cron\Jobs\ExpireHoldsJob;
use App\Services\Cron\Jobs\ExpireMembershipsJob;
use App\Services\Cron\Jobs\MembershipReminderJob;
use App\Services\Cron\Jobs\OpsAlertJob;
use App\Services\Cron\Jobs\PurgeRetentionJob;
use App\Services\Cron\Jobs\ReservationReminderJob;
use App\Services\Cron\Jobs\SendMailJob;

final class CronRunner
{
    private const LOCK = 'privofit_cron';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{ok: bool, skipped: bool, jobs: list<array{name: string, ok: bool, stats: array<string, int>, error: string, ms: int}>}
     */
    public function run(): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (!$this->lock()) {
            Logger::info('Cron přeskočen, předchozí běh ještě drží zámek');
            return ['ok' => true, 'skipped' => true, 'jobs' => []];
        }
        try {
            $notify = NotificationDispatcher::make($this->db);
            $jobs = [
                new ExpireHoldsJob($this->db, $notify),
                new ExpireMembershipsJob($this->db, $notify),
                new ReservationReminderJob($this->db, $notify),
                new MembershipReminderJob($this->db, $notify),
                new OpsAlertJob($this->db, $notify),
                new DailyRevenueJob($this->db, $notify),
                new DispatchNotificationsJob($notify),
                new SendMailJob($this->db),
                new PurgeRetentionJob($this->db),
            ];
            $results = [];
            $ok = true;
            foreach ($jobs as $job) {
                $started = microtime(true);
                try {
                    $stats = $job->run();
                    $results[] = [
                        'name' => $job->name(),
                        'ok' => true,
                        'stats' => $stats,
                        'error' => '',
                        'ms' => (int) round((microtime(true) - $started) * 1000),
                    ];
                } catch (\Throwable $e) {
                    $ok = false;
                    Logger::error('Cron úloha selhala', ['job' => $job->name(), 'error' => $e->getMessage()]);
                    $results[] = [
                        'name' => $job->name(),
                        'ok' => false,
                        'stats' => [],
                        'error' => $e->getMessage(),
                        'ms' => (int) round((microtime(true) - $started) * 1000),
                    ];
                }
            }
            return ['ok' => $ok, 'skipped' => false, 'jobs' => $results];
        } finally {
            $this->unlock();
        }
    }

    public function format(array $report): string
    {
        if (!empty($report['skipped'])) {
            return sprintf("[%s] cron skipped=lock\n", gmdate('c'));
        }
        $parts = [];
        foreach ($report['jobs'] as $job) {
            if (empty($job['ok'])) {
                $parts[] = $job['name'] . '=ERR';
                continue;
            }
            $bits = [];
            foreach ($job['stats'] as $key => $value) {
                $bits[] = $key . ':' . $value;
            }
            $parts[] = $job['name'] . '=' . ($bits === [] ? '0' : implode(',', $bits));
        }
        return sprintf("[%s] cron %s\n", gmdate('c'), implode(' ', $parts));
    }

    private function lock(): bool
    {
        $got = $this->db->fetchColumn('SELECT GET_LOCK(:name, 0)', ['name' => self::LOCK]);
        return (string) $got === '1';
    }

    private function unlock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK]);
        } catch (\Throwable) {
        }
    }
}
