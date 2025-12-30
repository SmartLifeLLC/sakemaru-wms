<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prevents migrate:reset from being executed.
 *
 * This command overrides the default migrate:reset command to prevent
 * accidental data loss in the shared database with the core system (sakemaru).
 */
class PreventMigrateResetCommand extends Command
{
    protected $signature = 'migrate:reset
                            {--database= : The database connection to use}
                            {--force : Force the operation to run when in production}
                            {--path=* : The path(s) to the migrations files to be executed}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--pretend : Dump the SQL queries that would be run}';

    protected $description = 'DISABLED: This command is prohibited to protect production data';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔═══════════════════════════════════════════════════════════════╗');
        $this->error('  ║                                                               ║');
        $this->error('  ║   ⛔ COMMAND PROHIBITED: migrate:reset                        ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   This command would rollback ALL migrations.                 ║');
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
