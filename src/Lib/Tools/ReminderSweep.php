<?php
declare(strict_types=1);

namespace App\Lib\Tools;

/**
 * Pure threshold-crossing helper for the PGP key reminder sweep.
 *
 * Given a key's expiry, the current `last_reminder_threshold` recorded for
 * that key, and the configured threshold cadence, decides whether a new
 * reminder should fire now and, if so, which threshold value to record.
 *
 * Semantics (see `reminder-sweep-prd.md` §5.2):
 *   - `last_reminder_threshold` is `NULL` when no reminder has been sent.
 *   - Otherwise it is the smallest threshold (in days before expiry) for
 *     which a reminder has been delivered. The special value `-1` denotes
 *     "the expired reminder has been sent".
 *   - A new send fires iff the newly-computed `crossed_threshold` is
 *     strictly smaller than the recorded one (or no value is recorded yet).
 *   - Thresholds are monotonically decreasing toward expiry: 30 → 7 → 1 → -1.
 */
class ReminderSweep
{
    /**
     * Identifier for the "key has expired" bucket.
     */
    public const EXPIRED = -1;

    /**
     * Decide whether a reminder should be sent now and which threshold to record.
     *
     * @param int $daysUntilExpiry Days from "now" until the key's soonest encryption-capable subkey expires.
     *     Negative values are accepted and treated as "expired" (caller may pass `$expired=true` explicitly,
     *     or just pass a negative number and leave `$expired=false`).
     * @param int|null $lastReminderThreshold Currently recorded `last_reminder_threshold` for the key.
     * @param array<int, int> $thresholds Configured threshold cadence in days. Must be positive ints. The
     *     method tolerates unsorted/duplicate input.
     * @param bool $expired When true, force the expired bucket regardless of `$daysUntilExpiry`.
     * @return int|null Threshold value to record (and to use as the mail trigger). `null` means
     *     "no send needed".
     */
    public static function computeCrossedThreshold(
        int $daysUntilExpiry,
        ?int $lastReminderThreshold,
        array $thresholds,
        bool $expired = false
    ): ?int {
        if ($expired || $daysUntilExpiry < 0) {
            $crossed = self::EXPIRED;
        } else {
            $crossed = self::smallestThresholdAtLeast($daysUntilExpiry, $thresholds);
            if ($crossed === null) {
                return null;
            }
        }

        if ($lastReminderThreshold !== null && $crossed >= $lastReminderThreshold) {
            return null;
        }

        return $crossed;
    }

    /**
     * Find the smallest threshold in the cadence that is ≥ the given day count.
     *
     * @param int $days Non-negative days-until-expiry value.
     * @param array<int, int> $thresholds Configured cadence; may be unsorted with dupes.
     * @return int|null Smallest matching threshold, or null when `$days` exceeds every threshold.
     */
    private static function smallestThresholdAtLeast(int $days, array $thresholds): ?int
    {
        $best = null;
        foreach ($thresholds as $threshold) {
            if ($threshold < $days) {
                continue;
            }
            if ($best === null || $threshold < $best) {
                $best = $threshold;
            }
        }

        return $best;
    }
}
