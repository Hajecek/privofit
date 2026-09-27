<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Core\Logger;

/**
 * Integrace Nuki Web API (https://api.nuki.io), dokumentace v1.5.3.
 *
 * Smart Lock Pro (3. gen / 4. gen) komunikuje přes vestavěné Wi-Fi, Bridge není nutný.
 * Autentizace: Authorization Bearer. Token se nikdy neposílá do prohlížeče.
 * HTTP 204 znamená, že API požadavek přijalo – nikoli nutně fyzické otevření.
 * serverState = 4 => zařízení offline.
 * Door sensor: 0 unavailable, 1 deactivated, 2 closed, 3 opened, 4 unknown, 5 calibrating.
 *
 * Komercní použití může vyžadovat Nuki Smart Hosting / Advanced API.
 * API token metoda je Nuki označována k postupnému ukončení; preferujte OAuth2.
 */
final class NukiDoorProvider implements DoorProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
        private readonly string $action,
    ) {
    }

    public function name(): string
    {
        return 'nuki';
    }

    public function open(array $door): DoorCommandResult
    {
        $smartlockId = rawurlencode((string) ($door['external_id'] ?? ''));
        $endpoint = match ($this->action) {
            'unlock' => "/smartlock/{$smartlockId}/action/unlock",
            'lock' => "/smartlock/{$smartlockId}/action/lock",
            default => "/smartlock/{$smartlockId}/action",
        };
        $body = $this->action === 'unlatch' ? json_encode(['action' => 3]) : '{}';

        return $this->dispatch($door, $endpoint, $body, true);
    }

    public function unlock(array $door): DoorCommandResult
    {
        $smartlockId = rawurlencode((string) ($door['external_id'] ?? ''));

        return $this->dispatch($door, "/smartlock/{$smartlockId}/action/unlock", '{}', true);
    }

    public function close(array $door): DoorCommandResult
    {
        $smartlockId = rawurlencode((string) ($door['external_id'] ?? ''));

        return $this->dispatch($door, "/smartlock/{$smartlockId}/action/lock", '{}', false);
    }

    private function dispatch(array $door, string $endpoint, string $body, bool $opening): DoorCommandResult
    {
        if ($this->token === '' || empty($door['external_id'])) {
            return new DoorCommandResult(false, 'failed', null, null, 'not_configured', 'Nuki API není nakonfigurováno.');
        }

        $response = $this->request('POST', $endpoint, $body);
        if ($response['error'] !== null) {
            return new DoorCommandResult(false, $response['statusCode'] === 0 ? 'timeout' : 'failed', null, null, $response['error'], 'Příkaz k Nuki API se nepodařilo odeslat.');
        }

        $accepted = in_array($response['statusCode'], [200, 204], true);
        $statusAfter = $this->status($door);
        $openStates = ['unlocked', 'unlatched', 'unlatching', 'unlocking', 'unlocked_lock_n_go'];
        $physicallyOpen = $opening && $accepted && in_array($statusAfter->lockState, $openStates, true);

        return new DoorCommandResult(
            accepted: $accepted,
            status: $accepted ? 'accepted' : 'failed',
            lockState: $statusAfter->lockState,
            doorState: $statusAfter->doorState,
            errorCode: $accepted ? null : 'http_' . $response['statusCode'],
            message: $accepted
                ? ($opening
                    ? 'Příkaz k otevření byl přijat. Fyzické otevření je potvrzené jen podle stavu zámku.'
                    : 'Příkaz k zavření byl přijat. Fyzické zavření je potvrzené jen podle stavu zámku.')
                : 'Nuki API příkaz odmítlo.',
            physicalOpenConfirmed: $physicallyOpen,
        );
    }

    public function status(array $door): DoorStatus
    {
        if ($this->token === '' || empty($door['external_id'])) {
            return new DoorStatus(false, null, null, null, false, 'nuki', 'unconfigured', 'missing_token_or_id');
        }
        $response = $this->request('GET', '/smartlock/' . rawurlencode((string) $door['external_id']));
        if ($response['error'] !== null || !is_array($response['json'])) {
            return new DoorStatus(false, null, null, null, false, 'nuki', 'error', $response['error'] ?? 'invalid_response');
        }
        $state = $response['json']['state'] ?? [];
        $serverState = (int) ($state['serverState'] ?? $response['json']['serverState'] ?? 0);
        $lock = $this->mapLockState((int) ($state['state'] ?? 255));
        $doorState = $this->mapDoorState((int) ($state['doorState'] ?? 0));
        $battery = isset($state['batteryCharge']) ? (int) $state['batteryCharge'] : null;
        $critical = (bool) ($state['batteryCritical'] ?? false);

        return new DoorStatus(
            online: $serverState !== 4,
            lockState: $lock,
            doorState: $doorState,
            batteryPercent: $battery,
            batteryCritical: $critical,
            provider: 'nuki',
            mode: 'live',
        );
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            Logger::error('Nuki API chyba spojení', ['path' => $path, 'errno' => $errno]);
            return ['statusCode' => 0, 'json' => null, 'error' => 'connection'];
        }

        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if ($status >= 400) {
            Logger::warning('Nuki API HTTP chyba', ['path' => $path, 'status' => $status]);
            return ['statusCode' => $status, 'json' => is_array($json) ? $json : null, 'error' => 'http'];
        }
        return ['statusCode' => $status, 'json' => is_array($json) ? $json : null, 'error' => null];
    }

    private function mapLockState(int $state): string
    {
        return match ($state) {
            1 => 'locked',
            2 => 'unlocking',
            3 => 'unlocked',
            4 => 'locking',
            5 => 'unlatched',
            6 => 'unlocked_lock_n_go',
            7 => 'unlatching',
            254 => 'motor_blocked',
            default => 'unknown',
        };
    }

    private function mapDoorState(int $state): string
    {
        return match ($state) {
            1 => 'deactivated',
            2 => 'closed',
            3 => 'opened',
            4 => 'unknown',
            5 => 'calibrating',
            default => 'unavailable',
        };
    }
}
