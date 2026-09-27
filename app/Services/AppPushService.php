<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Support\Clock;

final class AppPushService
{
    private static ?string $accessToken = null;

    private static int $accessTokenExp = 0;

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(Database $db): self
    {
        return new self($db);
    }

    public function accountStatusChanged(array $user, string $status, ?string $reason = null): void
    {
        $title = match ($status) {
            'blocked' => 'Účet byl zablokován',
            'active' => 'Účet je znovu aktivní',
            default => 'Stav účtu se změnil',
        };
        $defaultBlocked = 'Tvůj účet byl zablokován administrátorem.';
        $body = match ($status) {
            'blocked' => (trim((string) $reason) !== '' ? trim((string) $reason) : $defaultBlocked),
            'active' => 'Účet je zase v pořádku. Můžeš rezervovat a otevírat dveře.',
            default => 'Otevři aplikaci, stav tvého účtu se změnil.',
        };
        $this->notify((int) $user['id'], 'account-status', $title, $body, 'account.sync');
    }

    public function accountDeleted(array $user, ?string $reason = null): void
    {
        $body = trim((string) $reason);
        if ($body === '') {
            $body = 'Tvůj účet PRIVOFIT byl smazán administrátorem.';
        }
        $this->notify((int) $user['id'], 'account-deleted', 'Účet byl smazán', $body, 'account.sync');
    }

    public function membershipAssigned(int $userId): void
    {
        $this->notify(
            $userId,
            'membership-assigned',
            'Členství je aktivní',
            'V aplikaci uvidíš nový tarif a zbývající vstupy.',
            'membership.sync'
        );
    }

    public function membershipExpired(int $userId): void
    {
        $this->notify(
            $userId,
            'membership-expired',
            'Členství skončilo',
            'Platnost tarifu vypršela. Nové rezervace ze členství teď nejdou.',
            'membership.sync'
        );
    }

    public function liveChanged(int $revision): void
    {
        $now = microtime(true);
        static $last = 0.0;
        if ($last > 0 && ($now - $last) < 2) {
            return;
        }
        $last = $now;
        $this->sendFcmTopic('pf_live', 'live.sync', (string) $revision);
    }

    public function notify(int $userId, string $template, string $title, string $body, string $type): void
    {
        if ($userId < 1) {
            return;
        }
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'channel' => 'in_app',
            'template' => $template,
            'recipient' => 'app',
            'payload_json' => json_encode([
                'subject' => $title,
                'body' => $body,
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'sent',
            'sent_at' => Clock::utc(),
            'scheduled_at' => Clock::utc(),
            'created_at' => Clock::utc(),
        ]);
        $this->sendFcm($userId, $title, $body, $type);
    }

    private function sendFcm(int $userId, string $title, string $body, string $type): void
    {
        if ((string) env_value('APP_ENV', 'local') === 'testing') {
            return;
        }
        $tokens = $this->tokensFor($userId);
        if ($tokens === []) {
            return;
        }
        $account = $this->firebaseAccount();
        if ($account === null) {
            Logger::error('Push se neodeslal, chybí Firebase účet služby', [
                'user' => $userId,
                'tokens' => count($tokens),
            ]);
            return;
        }
        $accessToken = $this->accessToken($account);
        if ($accessToken === null) {
            return;
        }
        foreach ($tokens as $token) {
            $this->postFcm($account, $accessToken, $token, null, $title, $body, $type);
        }
    }

    /** @return list<string> */
    private function tokensFor(int $userId): array
    {
        $tokens = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT token FROM fcm_tokens
                 WHERE user_id = :uid AND is_active = 1 AND invalidated_at IS NULL AND token LIKE '%:%'",
                ['uid' => $userId]
            );
            foreach ($rows as $row) {
                $token = trim((string) ($row['token'] ?? ''));
                if (str_contains($token, ':')) {
                    $tokens[$token] = $token;
                }
            }
        } catch (\Throwable) {
            $tokens = [];
        }
        return array_values($tokens);
    }

    private function sendFcmTopic(string $topic, string $type, string $revision): void
    {
        if ((string) env_value('APP_ENV', 'local') === 'testing') {
            return;
        }
        $account = $this->firebaseAccount();
        if ($account === null) {
            return;
        }
        $accessToken = $this->accessToken($account);
        if ($accessToken === null) {
            return;
        }
        $this->postFcm($account, $accessToken, null, $topic, '', '', $type, $revision, true);
    }

    /** @return array{project_id:string,client_email:string,private_key:string}|null */
    private function firebaseAccount(): ?array
    {
        $path = trim((string) env_value('FIREBASE_CREDENTIALS', 'storage/firebase/privofit-firebase-adminsdk.json'));
        if ($path === '') {
            return null;
        }
        if ($path[0] !== '/') {
            $path = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
        }
        if (!is_readable($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                return null;
            }
        }
        return [
            'project_id' => $data['project_id'],
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
        ];
    }

    /** @param array{project_id:string,client_email:string,private_key:string} $account */
    private function accessToken(array $account, bool $force = false): ?string
    {
        if (!$force && self::$accessToken !== null && self::$accessTokenExp > time() + 60) {
            return self::$accessToken;
        }
        $cache = $this->accessTokenCachePath();
        if (!$force && is_readable($cache)) {
            $stored = json_decode((string) file_get_contents($cache), true);
            if (is_array($stored) && is_string($stored['token'] ?? null) && (int) ($stored['exp'] ?? 0) > time() + 60) {
                self::$accessToken = $stored['token'];
                self::$accessTokenExp = (int) $stored['exp'];
                return self::$accessToken;
            }
        }
        $now = time();
        $unsigned = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.'
            . $this->b64url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false) {
            Logger::error('Firebase klíč nejde načíst');
            return null;
        }
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Logger::error('Firebase klíč nejde podepsat');
            return null;
        }
        $jwt = $unsigned . '.' . $this->b64url($signature);
        $raw = $this->http('https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]), $status);
        $decoded = json_decode($raw, true);
        $token = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($status >= 400 || $token === '') {
            Logger::error('Firebase přístupový token se nepodařilo získat', ['status' => $status]);
            return null;
        }
        $exp = $now + max(120, (int) ($decoded['expires_in'] ?? 3600));
        self::$accessToken = $token;
        self::$accessTokenExp = $exp;
        $dir = dirname($cache);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($cache, json_encode(['token' => $token, 'exp' => $exp]));
        @chmod($cache, 0600);
        return $token;
    }

    private function forgetAccessToken(): void
    {
        self::$accessToken = null;
        self::$accessTokenExp = 0;
        $cache = $this->accessTokenCachePath();
        if (is_file($cache)) {
            unlink($cache);
        }
    }

    private function accessTokenCachePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/fcm-oauth.json';
    }

    /**
     * @param array{project_id:string,client_email:string,private_key:string} $account
     */
    private function postFcm(
        array $account,
        string $accessToken,
        ?string $deviceToken,
        ?string $topic,
        string $title,
        string $body,
        string $type,
        string $revision = '',
        bool $silent = false,
    ): void {
        $status = $this->deliverFcm($account, $accessToken, $deviceToken, $topic, $title, $body, $type, $revision, $silent);
        if ($status !== 401) {
            return;
        }
        $this->forgetAccessToken();
        $fresh = $this->accessToken($account, true);
        if ($fresh === null) {
            return;
        }
        $this->deliverFcm($account, $fresh, $deviceToken, $topic, $title, $body, $type, $revision, $silent);
    }

    /**
     * @param array{project_id:string,client_email:string,private_key:string} $account
     */
    private function deliverFcm(
        array $account,
        string $accessToken,
        ?string $deviceToken,
        ?string $topic,
        string $title,
        string $body,
        string $type,
        string $revision,
        bool $silent,
    ): int {
        $message = [
            'data' => [
                'type' => $type,
                'revision' => $revision,
            ],
        ];
        if ($deviceToken !== null && $deviceToken !== '') {
            $message['token'] = $deviceToken;
        } elseif ($topic !== null && $topic !== '') {
            $message['topic'] = $topic;
        } else {
            return 0;
        }
        if (!$silent && $title !== '') {
            $message['notification'] = [
                'title' => $title,
                'body' => $body,
            ];
            $message['android'] = [
                'priority' => 'HIGH',
                'notification' => ['sound' => 'default'],
            ];
            $message['apns'] = [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default']],
            ];
        } else {
            $message['android'] = ['priority' => 'HIGH'];
            $message['apns'] = [
                'headers' => [
                    'apns-priority' => '5',
                    'apns-push-type' => 'background',
                ],
                'payload' => ['aps' => ['content-available' => 1]],
            ];
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/messages:send';
        $raw = $this->http($url, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ], (string) json_encode(['message' => $message], JSON_UNESCAPED_UNICODE), $status);
        if ($status >= 400) {
            $errorCode = $this->fcmErrorCode($raw);
            Logger::error('FCM se nepodařilo odeslat', [
                'status' => $status,
                'error' => $errorCode,
            ]);
            if ($deviceToken !== null && in_array($errorCode, ['UNREGISTERED', 'NOT_FOUND'], true)) {
                $this->invalidateToken($deviceToken, $errorCode);
            }
        }
        return $status;
    }

    private function fcmErrorCode(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $code = (string) ($decoded['error']['status'] ?? '');
        foreach ($decoded['error']['details'] ?? [] as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null) && $detail['errorCode'] !== '') {
                return $detail['errorCode'];
            }
        }
        return $code;
    }

    private function invalidateToken(string $token, string $reason): void
    {
        try {
            $this->db->query(
                'UPDATE fcm_tokens
                 SET is_active = 0, invalidated_at = :now, invalid_reason = :reason
                 WHERE token_hash = :hash AND is_active = 1',
                [
                    'now' => Clock::utc(),
                    'reason' => substr($reason, 0, 255),
                    'hash' => hash('sha256', $token),
                ]
            );
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $headers */
    private function http(string $url, array $headers, string $body, ?int &$status): string
    {
        $status = 0;
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($raw) ? $raw : '';
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
