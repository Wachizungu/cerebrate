<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

final class ReminderSweepSchema extends AbstractMigration
{
    public $autoId = false;

    /**
     * Add the nullable `last_reminder_threshold` smallint column used by the
     * `check_expiring_keys` cron sweep to track which threshold was last
     * notified for each individual-owned PGP key.
     *
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('encryption_keys');
        if (!$table->hasColumn('last_reminder_threshold')) {
            $table
                ->addColumn('last_reminder_threshold', 'smallinteger', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Smallest threshold (in days before expiry) for which a reminder has been '
                        . 'delivered for this key. -1 = expired reminder sent. NULL = none yet.',
                ])
                ->update();
        }
    }
}
