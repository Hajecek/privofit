<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;

final class PurgeRetentionJob implements CronJob
{
    public function __construct(private readonly Database $db)
    {
    }

    public function name(): string
    {
        return 'purge';
    }

    public function run(): array
    {
        $accessDays = $this->days('gdpr_access_log_retention_days', 365);
        $auditDays = $this->days('gdpr_audit_log_retention_days', 730);
        $this->db->query('DELETE FROM rate_limit_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
        $this->db->query('DELETE FROM login_attempts WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)');
        $this->db->query(
            'DELETE FROM access_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $accessDays . ' DAY)'
        );
        $this->db->query(
            'DELETE FROM admin_audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $auditDays . ' DAY)'
        );
        $events = $this->db->query(
            "DELETE FROM cron_events
             WHERE status IN ('dispatched', 'skipped', 'failed')
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"
        )->rowCount();
        $notes = $this->db->query(
            "DELETE FROM notifications
             WHERE status IN ('sent', 'failed')
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)"
        )->rowCount();
        return ['events' => $events, 'notifications' => $notes];
    }

    private function days(string $key, int $default): int
    {
        try {
            $value = (int) config('app.' . $key, $default);
        } catch (\Throwable) {
            $value = $default;
        }
        return max(1, $value);
    }
}
