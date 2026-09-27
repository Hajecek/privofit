<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cron\DueWindow;
use PHPUnit\Framework\TestCase;

final class DueWindowTest extends TestCase
{
    public function testOpensAtTheLeadAndStaysClosedAfterTheTarget(): void
    {
        $target = $this->at('2026-09-27 18:00:00');
        $created = $this->at('2026-09-20 08:00:00');

        $this->assertFalse(DueWindow::open($this->at('2026-09-27 16:59:59'), $target, $created, 60));
        $this->assertTrue(DueWindow::open($this->at('2026-09-27 17:00:00'), $target, $created, 60));
        $this->assertTrue(DueWindow::open($this->at('2026-09-27 17:30:00'), $target, $created, 60));
        $this->assertFalse(DueWindow::open($this->at('2026-09-27 18:00:00'), $target, $created, 60));
    }

    public function testEarlierReminderClosesWhenTheLaterOneOpens(): void
    {
        $target = $this->at('2026-09-28 18:00:00');
        $created = $this->at('2026-09-01 08:00:00');

        $this->assertTrue(DueWindow::open($this->at('2026-09-27 18:00:00'), $target, $created, 1440, 60));
        $this->assertFalse(DueWindow::open($this->at('2026-09-28 17:00:00'), $target, $created, 1440, 60));
        $this->assertTrue(DueWindow::open($this->at('2026-09-28 17:00:00'), $target, $created, 60));
    }

    public function testBookingMadeInsideTheWindowDoesNotGetAReminder(): void
    {
        $target = $this->at('2026-09-27 18:00:00');
        $now = $this->at('2026-09-27 17:20:00');
        $created = $this->at('2026-09-27 17:10:00');

        $this->assertFalse(DueWindow::open($now, $target, $created, 60));
    }

    public function testInvalidLeadIsClosed(): void
    {
        $target = $this->at('2026-09-27 18:00:00');
        $now = $this->at('2026-09-27 17:20:00');
        $created = $this->at('2026-09-20 08:00:00');

        $this->assertFalse(DueWindow::open($now, $target, $created, 60, 60));
        $this->assertFalse(DueWindow::open($now, $target, $created, 0));
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
