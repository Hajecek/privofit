<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Cron\CronText;
use App\Services\Cron\NotificationDispatcher;
use App\Support\Clock;

/** Okamžitá zpráva administrátorům, když je rezervace potvrzená. */
final class ReservationAdminNotice
{
    public function __construct(private readonly Database $db)
    {
    }

    public function send(int $reservationId): void
    {
        if ($reservationId < 1) {
            return;
        }
        try {
            $row = $this->db->fetch(
                "SELECT r.id, r.starts_at, r.ends_at, r.guest_count, r.price, r.currency, r.status,
                        u.first_name, u.last_name, rm.name AS room_name
                 FROM reservations r
                 INNER JOIN users u ON u.id = r.user_id
                 INNER JOIN rooms rm ON rm.id = r.room_id
                 WHERE r.id = :id AND r.status = 'confirmed'",
                ['id' => $reservationId]
            );
            if (!$row) {
                return;
            }
            $payment = $this->db->fetch(
                "SELECT amount, charged_amount, currency
                 FROM payments
                 WHERE reservation_id = :id AND status = 'paid'
                 ORDER BY id DESC
                 LIMIT 1",
                ['id' => $reservationId]
            );
            $money = $this->money(is_array($payment) ? $payment : $row);
            $when = $this->when($row);
            $room = trim((string) ($row['room_name'] ?? ''));
            $guests = $this->guests((int) ($row['guest_count'] ?? 1));
            $settlement = $money !== '' ? $money : 'vstup z tarifu';
            $parts = array_values(array_filter(
                [CronText::person($row), $when, $room, $guests, $settlement],
                static fn (string $part): bool => $part !== ''
            ));
            NotificationDispatcher::make($this->db)->notifyNow(
                'admin:reservation.booked:' . $reservationId,
                'admin',
                'reservation.booked',
                null,
                [
                    'template' => 'admin-reservation',
                    'push_type' => 'admin.sync',
                    'subject' => $money !== '' ? '🗓️ Rezervace zaplacena' : '🗓️ Nová rezervace',
                    'body' => implode(' · ', $parts),
                    'action_url' => CronText::link('/user/sprava/rezervace'),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Zpráva administrátorům o rezervaci se neodeslala', [
                'reservation' => $reservationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function money(array $row): string
    {
        $charged = (float) ($row['charged_amount'] ?? 0);
        $amount = $charged > 0 ? $charged : (float) ($row['price'] ?? $row['amount'] ?? 0);
        if ($amount <= 0) {
            return '';
        }
        $currency = strtoupper((string) ($row['currency'] ?? 'CZK'));
        $formatted = number_format($amount, 2, ',', ' ');
        return $currency === 'CZK' ? $formatted . ' Kč' : $formatted . ' ' . $currency;
    }

    /** @param array<string, mixed> $row */
    private function when(array $row): string
    {
        try {
            return Clock::format((string) $row['starts_at'], 'j. n. Y H:i')
                . '–'
                . Clock::format((string) $row['ends_at'], 'H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function guests(int $count): string
    {
        if ($count <= 1) {
            return '';
        }
        $word = $count < 5 ? 'osoby' : 'osob';
        return $count . ' ' . $word;
    }
}
