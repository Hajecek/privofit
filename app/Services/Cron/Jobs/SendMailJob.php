<?php

declare(strict_types=1);

namespace App\Services\Cron\Jobs;

use App\Core\Database;
use App\Services\Cron\CronJob;
use App\Services\MailService;

final class SendMailJob implements CronJob
{
    public function __construct(private readonly Database $db)
    {
    }

    public function name(): string
    {
        return 'send-mail';
    }

    public function run(): array
    {
        return ['sent' => (new MailService($this->db))->processPending(50)];
    }
}
