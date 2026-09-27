<?php

declare(strict_types=1);

namespace App\Services\Access;

/**
 * Testovací poskytovatel. Nikdy netvrdí, že fyzicky otevřel dveře.
 */
final class MockDoorProvider implements DoorProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function open(array $door): DoorCommandResult
    {
        return new DoorCommandResult(
            accepted: true,
            status: 'accepted',
            lockState: 'unlocked',
            doorState: 'unknown',
            message: 'Testovací režim: příkaz byl přijat mock poskytovatelem. Fyzické dveře nebyly ovládány.',
            physicalOpenConfirmed: false,
        );
    }

    public function unlock(array $door): DoorCommandResult
    {
        return new DoorCommandResult(
            accepted: true,
            status: 'accepted',
            lockState: 'unlocked',
            doorState: 'opened',
            message: 'Testovací režim: zámek je vedený jako otevřený. Fyzické dveře nebyly ovládány.',
            physicalOpenConfirmed: false,
        );
    }

    public function close(array $door): DoorCommandResult
    {
        return new DoorCommandResult(
            accepted: true,
            status: 'accepted',
            lockState: 'locked',
            doorState: 'closed',
            message: 'Testovací režim: zámek je vedený jako zavřený. Fyzické dveře nebyly ovládány.',
            physicalOpenConfirmed: false,
        );
    }

    public function status(array $door): DoorStatus
    {
        return new DoorStatus(
            online: true,
            lockState: $door['last_known_state'] ?? 'locked',
            doorState: $door['last_known_door_state'] ?? 'closed',
            batteryPercent: (int) ($door['last_battery_percent'] ?? 100),
            batteryCritical: false,
            provider: 'mock',
            mode: 'test',
        );
    }
}
