<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prevents db:wipe from being executed.
 *
 * This command overrides the default db:wipe command to prevent
 * accidental data loss in the shared database with the core system (sakemaru).
 */
class PreventDbWipeCommand extends Command
{
    protected $signature = 'db:wipe
                            {--database= : The database connection to use}
                            {--drop-views : Drop all tables and views}
                            {--drop-types : Drop all tables and types (Postgres only)}
                            {--force : Force the operation to run when in production}';

    protected $description = 'DISABLED: This command is prohibited to protect production data';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔═══════════════════════════════════════════════════════════════╗');
        $this->error('  ║                                                               ║');
        $this->error('  ║   ⛔ COMMAND PROHIBITED: db:wipe                              ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   This command would DELETE the entire database.              ║');
        $this->error('  ║   This database is shared with the core system (sakemaru).    ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   Allowed commands:                                           ║');
        $this->error('  ║   - php artisan migrate          (run new migrations)         ║');
        $this->error('  ║   - php artisan migrate:status   (check status)               ║');
        $this->error('  ║   - php artisan make:migration   (create migration)           ║');
        $this->error('  ║                                                               ║');
        $this->error('  ╚═══════════════════════════════════════════════════════════════╝');
        $this->error('');

        return self::FAILURE;
    }
}
