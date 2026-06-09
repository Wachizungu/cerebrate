<?php
declare(strict_types=1);

namespace App\Test\TestCase\Lib\Tools;

use App\Lib\Tools\ReminderSweep;
use Cake\TestSuite\TestCase;

/**
 * @covers \App\Lib\Tools\ReminderSweep
 */
class ReminderSweepTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int|null, 2: array<int, int>, 3: bool, 4: int|null}>
     */
    public static function thresholdProvider(): array
    {
        $cadence = [30, 7, 1];

        return [
            'first contact: 30 days out, first time, crosses 30' => [30, null, $cadence, false, 30],
            'first contact: 29 days out, first time, crosses 30' => [29, null, $cadence, false, 30],
            'first contact: 7 days out, first time, crosses 7' => [7, null, $cadence, false, 7],
            'first contact: 1 day out, first time, crosses 1' => [1, null, $cadence, false, 1],
            'first contact: 0 days out (expiry today) crosses 1' => [0, null, $cadence, false, 1],
            'first contact: 31 days out is beyond cadence' => [31, null, $cadence, false, null],
            'after 30 send: 29 days out is the same bucket' => [29, 30, $cadence, false, null],
            'after 30 send: 7 days out crosses 7' => [7, 30, $cadence, false, 7],
            'after 30 send: 8 days out is still 30 bucket' => [8, 30, $cadence, false, null],
            'after 7 send: 6 days out is still 7 bucket' => [6, 7, $cadence, false, null],
            'after 7 send: 1 day out crosses 1' => [1, 7, $cadence, false, 1],
            'after 1 send: 0 days out is still 1 bucket' => [0, 1, $cadence, false, null],
            'after 1 send: expired flag crosses -1' => [-1, 1, $cadence, true, ReminderSweep::EXPIRED],
            'after 1 send: negative days crosses -1 implicitly' => [-1, 1, $cadence, false, ReminderSweep::EXPIRED],
            'already expired-notified: still expired, no resend' => [-3, -1, $cadence, false, null],
            'unsorted cadence still works' => [6, null, [1, 30, 7], false, 7],
            'duplicate cadence values are tolerated' => [6, null, [7, 7, 30], false, 7],
            'single-threshold cadence: under triggers' => [3, null, [7], false, 7],
            'single-threshold cadence: over does not trigger' => [8, null, [7], false, null],
            'no recorded threshold but already expired' => [-1, null, $cadence, true, ReminderSweep::EXPIRED],
        ];
    }

    /**
     * @dataProvider thresholdProvider
     * @param int $daysUntil Days-until-expiry input.
     * @param int|null $last Recorded `last_reminder_threshold`.
     * @param array<int, int> $thresholds Cadence.
     * @param bool $expired Force-expired flag.
     * @param int|null $expected Expected return value.
     */
    public function testComputeCrossedThreshold(
        int $daysUntil,
        ?int $last,
        array $thresholds,
        bool $expired,
        ?int $expected
    ): void {
        $this->assertSame(
            $expected,
            ReminderSweep::computeCrossedThreshold($daysUntil, $last, $thresholds, $expired)
        );
    }
}
