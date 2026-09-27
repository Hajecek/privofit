<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Core\Database;
use App\Core\Logger;
use App\Services\AppPushService;
use App\Services\MailService;
use App\Support\Clock;

/**
 * Zapíše cron událost jednou a při odeslání ji rozdělí na zákaznickou, nebo administrátorskou notifikaci.
 * Zákaznický e-mail ctí notification_preferences. Administrátorská událost jde jen na aktivní účty s rolí admin.
 */
final class NotificationDispatcher
{
    private const PREFERENCES = [
        'email_reservations',
        'email_reminders',
        'email_membership',
        'email_marketing',
        'email_security',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MailService $mail,
        private readonly AppPushService $push,
    ) {
    }

    public static function make(Database $db): self
    {
        return new self($db, new MailService($db), AppPushService::make($db));
    }

    /** @param array<string, mixed> $payload */
    public function schedule(string $eventKey, string $audience, string $eventType, ?int $userId, array $payload): bool
    {
        if (!in_array($audience, ['user', 'admin'], true)) {
            throw new \InvalidArgumentException('Neznámé publikum notifikace.');
        }
        if ($audience === 'user' && ($userId === null || $userId < 1)) {
            return false;
        }
        if ($audience === 'admin') {
            $userId = null;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }
        $now = Clock::utc();
        try {
            $this->db->insert('cron_events', [
                'event_key' => $eventKey,
                'audience' => $audience,
                'event_type' => $eventType,
                'user_id' => $userId,
                'payload_json' => $encoded,
                'status' => 'pending',
                'scheduled_at' => $now,
                'created_at' => $now,
            ]);
            return true;
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? '');
            $driver = (int) ($e->errorInfo[1] ?? 0);
            if ($state === '23000' || $driver === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Zapíše událost a hned ji rozešle. Druhé volání se stejným klíčem už nic nepošle.
     *
     * @param array<string, mixed> $payload
     */
    public function notifyNow(string $eventKey, string $audience, string $eventType, ?int $userId, array $payload): string
    {
        $created = $this->schedule($eventKey, $audience, $eventType, $userId, $payload);
        $status = $this->dispatchKey($eventKey);
        if ($created && $status === 'dispatched' && (string) env_value('APP_ENV', 'local') !== 'testing') {
            $this->mail->processPending(20);
        }
        return $status;
    }

    public function dispatchDue(int $limit = 100): int
    {
        $this->releaseStaleClaims();
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            "SELECT * FROM cron_events
             WHERE status = 'pending' AND scheduled_at <= :now
             ORDER BY id ASC
             LIMIT {$limit}",
            ['now' => Clock::utc()]
        );
        $done = 0;
        foreach ($rows as $row) {
            if ($this->dispatchRow($row) === 'dispatched') {
                $done++;
            }
        }
        return $done;
    }

    public function dispatchKey(string $eventKey): string
    {
        $row = $this->db->fetch('SELECT * FROM cron_events WHERE event_key = :key', ['key' => $eventKey]);
        if (!$row) {
            return 'missing';
        }
        if (!in_array((string) $row['status'], ['pending', 'dispatching'], true)) {
            return (string) $row['status'];
        }
        return $this->dispatchRow($row);
    }

    /** @param array<string, mixed> $row */
    private function dispatchRow(array $row): string
    {
        $id = (int) $row['id'];
        $claimed = $this->db->query(
            "UPDATE cron_events SET status = 'dispatching' WHERE id = :id AND status = 'pending'",
            ['id' => $id]
        )->rowCount();
        if ($claimed < 1 && (string) $row['status'] !== 'dispatching') {
            $fresh = $this->db->fetch('SELECT status FROM cron_events WHERE id = :id', ['id' => $id]);
            return (string) ($fresh['status'] ?? 'pending');
        }
        try {
            $status = (string) $row['audience'] === 'admin'
                ? $this->deliverAdmin($row)
                : $this->deliverUser($row);
            $this->db->update('cron_events', [
                'status' => $status,
                'dispatched_at' => Clock::utc(),
                'last_error' => null,
            ], 'id = :id', ['id' => $id]);
            return $status;
        } catch (\Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            Logger::error('Cron notifikace selhala', ['id' => $id, 'error' => $e->getMessage()]);
            $this->db->update('cron_events', [
                'status' => $attempts >= 5 ? 'failed' : 'pending',
                'attempts' => $attempts,
                'last_error' => substr($e->getMessage(), 0, 500),
            ], 'id = :id', ['id' => $id]);
            return $attempts >= 5 ? 'failed' : 'pending';
        }
    }

    /** @param array<string, mixed> $row */
    private function deliverUser(array $row): string
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $user = $userId > 0
            ? $this->db->fetch('SELECT id, email, first_name, status FROM users WHERE id = :id', ['id' => $userId])
            : null;
        if (!$user || !in_array((string) $user['status'], ['active', 'pending'], true)) {
            return 'skipped';
        }
        $payload = $this->payload($row);
        $preference = isset($payload['preference']) ? (string) $payload['preference'] : '';
        if (!$this->allows($userId, $preference)) {
            return 'skipped';
        }
        $this->sendTo((int) $user['id'], (string) $user['email'], (string) $user['first_name'], $payload);
        return 'dispatched';
    }

    /** @param array<string, mixed> $row */
    private function deliverAdmin(array $row): string
    {
        $admins = $this->db->fetchAll(
            "SELECT id, email, first_name FROM users WHERE role = 'admin' AND status = 'active'"
        );
        if ($admins === []) {
            Logger::warning('Cron událost pro administrátory nemá komu odejít', ['id' => $row['id']]);
            return 'dispatched';
        }
        $payload = $this->payload($row);
        unset($payload['preference']);
        foreach ($admins as $admin) {
            $this->sendTo((int) $admin['id'], (string) $admin['email'], (string) $admin['first_name'], $payload);
        }
        return 'dispatched';
    }

    /** @param array<string, mixed> $payload */
    private function sendTo(int $userId, string $email, string $firstName, array $payload): void
    {
        $payload['first_name'] = $firstName;
        $template = (string) ($payload['template'] ?? 'generic');
        $title = (string) ($payload['subject'] ?? 'PRIVOFIT');
        $body = (string) ($payload['body'] ?? '');
        if ($email !== '' && empty($payload['skip_email'])) {
            unset($payload['skip_email']);
            $this->mail->queue($template, $email, $payload, $userId);
        }
        $this->push->notify($userId, $template, $title, $body, (string) ($payload['push_type'] ?? 'account.sync'));
    }

    private function allows(int $userId, string $preference): bool
    {
        if ($preference === '' || !in_array($preference, self::PREFERENCES, true)) {
            return true;
        }
        $row = $this->db->fetch(
            'SELECT * FROM notification_preferences WHERE user_id = :id',
            ['id' => $userId]
        );
        if (!$row) {
            return true;
        }
        return (int) ($row[$preference] ?? 1) === 1;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    private function releaseStaleClaims(): void
    {
        $this->db->query(
            "UPDATE cron_events
             SET status = 'pending'
             WHERE status = 'dispatching'
               AND scheduled_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
    }
}
