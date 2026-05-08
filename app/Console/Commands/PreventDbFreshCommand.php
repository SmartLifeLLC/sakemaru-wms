<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prevents db:fresh from being executed.
 *
 * This command reserves db:fresh as a prohibited command name to prevent
 * accidental data loss in the shared database with the core system (sakemaru).
 */
class PreventDbFreshCommand extends Command
{
    protected $signature = 'db:fresh
                            {--database= : The database connection to use}
                            {--force : Force the operation to run when in production}
                            {--seed : Indicates if the seed task should be re-run}
                            {--seeder= : The class name of the root seeder}';

    protected $description = 'DISABLED: This command is prohibited to protect production data';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔═══════════════════════════════════════════════════════════════╗');
        $this->error('  ║                                                               ║');
        $this->error('  ║   ⛔ COMMAND PROHIBITED: db:fresh                             ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   This command name is reserved as prohibited.                ║');
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
