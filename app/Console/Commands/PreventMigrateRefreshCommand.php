<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prevents migrate:refresh from being executed.
 *
 * This command overrides the default migrate:refresh command to prevent
 * accidental data loss in the shared database with the core system (sakemaru).
 */
class PreventMigrateRefreshCommand extends Command
{
    protected $signature = 'migrate:refresh
                            {--database= : The database connection to use}
                            {--force : Force the operation to run when in production}
                            {--path=* : The path(s) to the migrations files to be executed}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--seed : Indicates if the seed task should be re-run}
                            {--seeder= : The class name of the root seeder}
                            {--step= : The number of migrations to be reverted & re-run}';

    protected $description = 'DISABLED: This command is prohibited to protect production data';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔═══════════════════════════════════════════════════════════════╗');
        $this->error('  ║                                                               ║');
        $this->error('  ║   ⛔ COMMAND PROHIBITED: migrate:refresh                      ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   This command would rollback and re-run ALL migrations.      ║');
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