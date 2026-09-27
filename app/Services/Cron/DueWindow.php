<?php

declare(strict_types=1);

namespace App\Services\Cron;

/**
 * Okno, ve kterém se připomínka smí odeslat právě jednou.
 * Otevře se `leadMinutes` před termínem a volitelně se zavře `untilLeadMinutes` před ním,
 * aby pozdější připomínka nepřekryla tu dřívější. Rezervace založená až po otevření okna
 * připomínku nedostane — stačí potvrzení.
 */
final class DueWindow
{
    public static function open(
        \DateTimeImmutable $now,
        \DateTimeImmutable $target,
        \DateTimeImmutable $createdAt,
        int $leadMinutes,
        ?int $untilLeadMinutes = null,
    ): bool {
        if ($leadMinutes < 1 || ($untilLeadMinutes !== null && $untilLeadMinutes >= $leadMinutes)) {
            return false;
        }
        $opensAt = $target->modify('-' . $leadMinutes . ' minutes');
        if ($now < $opensAt || $now >= $target) {
            return false;
        }
        if ($untilLeadMinutes !== null && $now >= $target->modify('-' . $untilLeadMinutes . ' minutes')) {
            return false;
        }
        return $createdAt <= $opensAt;
    }
}
