<?php

declare(strict_types=1);

namespace App\Services\Access;

interface DoorProviderInterface
{
    public function name(): string;

    public function open(array $door): DoorCommandResult;

    public function unlock(array $door): DoorCommandResult;

    public function close(array $door): DoorCommandResult;

    public function status(array $door): DoorStatus;
}
