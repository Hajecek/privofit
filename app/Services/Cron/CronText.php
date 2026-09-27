<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Support\Clock;

final class CronText
{
    public static function when(string $utc): string
    {
        try {
            return Clock::format($utc, 'j. n. Y H:i');
        } catch (\Throwable) {
            return $utc . ' UTC';
        }
    }

    public static function person(array $row): string
    {
        $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        return $name !== '' ? $name : 'Zákazník';
    }

    public static function link(string $path): string
    {
        try {
            return absolute_url($path);
        } catch (\Throwable) {
            $base = rtrim((string) env_value('APP_URL', ''), '/');
            $path = '/' . ltrim($path, '/');
            return $base . $path;
        }
    }
}
