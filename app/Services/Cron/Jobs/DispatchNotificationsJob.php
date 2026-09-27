<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Services\Cron\CronJob;
use App\Services\Cron\NotificationDispatcher;

final class DispatchNotificationsJob implements CronJob
{
    public function __construct(private readonly NotificationDispatcher $notify)
    {
    }

    public function name(): string
    {
        return 'dispatch-notifications';
    }

    public function run(): array
    {
        return ['dispatched' => $this->notify->dispatchDue(100)];
    }
}
