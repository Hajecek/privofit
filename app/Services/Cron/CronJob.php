<?php

declare(strict_types=1);

namespace App\Services\Cron;

interface CronJob
{
    public function name(): string;

    /** @return array<string, int> */
    public function run(): array;
}
