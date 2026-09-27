<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use App\Support\Clock;
use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function queue(string $template, string $recipient, array $payload, ?int $userId = null, ?string $scheduledAt = null): void
    {
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'channel' => 'email',
            'template' => $template,
            'recipient' => $recipient,
            'payload_json' => json_encode($this->withAbsoluteLinks($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'scheduled_at' => $scheduledAt ?? Clock::utc(),
            'created_at' => Clock::utc(),
        ]);

        $this->db->afterCommit(function (): void {
            try {
                $this->processPending(20);
            } catch (\Throwable $e) {
                Logger::error('Fronta e-mailů selhala', ['error' => $e->getMessage()]);
            }
        });
    }

    public function processPending(int $limit = 20): int
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM notifications
             WHERE channel = 'email' AND status = 'pending' AND scheduled_at <= :now
             ORDER BY id ASC LIMIT {$limit}",
            ['now' => Clock::utc()]
        );
        $sent = 0;
        foreach ($rows as $row) {
            try {
                $this->sendRow($row);
                $this->db->update('notifications', [
                    'status' => 'sent',
                    'sent_at' => Clock::utc(),
                    'last_error' => null,
                ], 'id = :id', ['id' => (int) $row['id']]);
                $sent++;
            } catch (\Throwable $e) {
                Logger::error('Odeslání e-mailu selhalo', ['id' => $row['id'], 'error' => $e->getMessage()]);
                $this->db->update('notifications', [
                    'status' => ((int) $row['attempts'] + 1) >= 5 ? 'failed' : 'pending',
                    'attempts' => (int) $row['attempts'] + 1,
                    'last_error' => substr($e->getMessage(), 0, 500),
                ], 'id = :id', ['id' => (int) $row['id']]);
            }
        }
        return $sent;
    }

    private function sendRow(array $row): void
    {
        $payload = $this->withAbsoluteLinks(json_decode((string) $row['payload_json'], true) ?: []);
        $html = $this->render((string) $row['template'], $payload);
        $subject = (string) ($payload['subject'] ?? 'PRIVOFIT');
        $mailer = (string) env_value('MAIL_MAILER', 'log');

        if ($mailer === 'log') {
            Logger::info('E-mail (log)', [
                'to' => $row['recipient'],
                'subject' => $subject,
                'template' => $row['template'],
            ]);
            Logger::append(
                'mail-' . gmdate('Y-m-d') . '.log',
                sprintf("[%s] TO=%s SUBJECT=%s\n%s\n\n", Clock::utc(), $row['recipient'], $subject, $html)
            );
            $this->rememberLocalLink($payload);
            return;
        }

        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $from = (string) env_value('MAIL_FROM_ADDRESS', 'noreply@privofit.cz');
        $mail->setFrom($from, (string) env_value('MAIL_FROM_NAME', 'PRIVOFIT'));
        $mail->Sender = $from;
        $mail->addAddress((string) $row['recipient']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        if (!empty($payload['action_url'])) {
            $plain .= "\n\n" . $payload['action_url'];
        }
        $mail->AltBody = $plain;

        if ($mailer === 'mail' || $mailer === 'sendmail') {
            $mail->isMail();
            $mail->send();
            return;
        }

        $host = trim((string) env_value('MAIL_HOST', ''));
        if ($host === '' || $host === 'smtp.example.com') {
            throw new \RuntimeException('SMTP není nastavené. Vyplňte MAIL_HOST, nebo použijte MAIL_MAILER=mail.');
        }
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) env_value('MAIL_PORT', 587);
        $mail->SMTPAuth = (string) env_value('MAIL_USERNAME', '') !== '';
        $mail->Username = (string) env_value('MAIL_USERNAME', '');
        $mail->Password = (string) env_value('MAIL_PASSWORD', '');
        $mail->SMTPAutoTLS = false;
        $enc = strtolower((string) env_value('MAIL_ENCRYPTION', 'tls'));
        if ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        $mail->send();
    }

    /** @param array<string, mixed> $payload */
    private function rememberLocalLink(array $payload): void
    {
        if (PHP_SAPI === 'cli' || !config('app.debug')) {
            return;
        }
        $url = (string) ($payload['action_url'] ?? '');
        if (preg_match('#^https?://#i', $url) !== 1) {
            return;
        }
        $links = Session::get('flash_mail_links', []);
        if (!is_array($links)) {
            $links = [];
        }
        $links[] = [
            'subject' => (string) ($payload['subject'] ?? 'E-mail'),
            'url' => $url,
        ];
        Session::set('flash_mail_links', $links);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withAbsoluteLinks(array $payload): array
    {
        if (!empty($payload['action_url']) && is_string($payload['action_url'])) {
            $payload['action_url'] = $this->absoluteLink($payload['action_url']);
        }
        $payload['logo_url'] = $this->absoluteLink(url('/assets/brand/logo-transparent.png')) . '?v=2';
        return $payload;
    }

    private function absoluteLink(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $base = rtrim((string) config('app.url', ''), '/');
        if (!str_starts_with($href, '/')) {
            $href = '/' . $href;
        }
        if (preg_match('#^https?://#i', $base) === 1) {
            return $base . $href;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host . $href;
    }

    private function render(string $template, array $payload): string
    {
        $path = dirname(__DIR__, 2) . '/resources/emails/' . $template . '.php';
        if (!is_file($path)) {
            $path = dirname(__DIR__, 2) . '/resources/emails/generic.php';
        }
        extract($payload, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}
