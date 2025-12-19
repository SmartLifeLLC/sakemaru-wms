<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prevents migrate:fresh from being executed.
 *
 * This command overrides the default migrate:fresh command to prevent
 * accidental data loss in the shared database with the core system (sakemaru).
 */
class PreventMigrateFreshCommand extends Command
{
    protected $signature = 'migrate:fresh
                            {--database= : The database connection to use}
                            {--drop-views : Drop all tables and views}
                            {--drop-types : Drop all tables and types (Postgres only)}
                            {--force : Force the operation to run when in production}
                            {--path=* : The path(s) to the migrations files to be executed}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--schema-path= : The path to a schema dump file}
                            {--seed : Indicates if the seed task should be re-run}
                            {--seeder= : The class name of the root seeder}
                            {--step : Force the migrations to be run so they can be rolled back individually}';

    protected $description = 'DISABLED: This command is prohibited to protect production data';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔═══════════════════════════════════════════════════════════════╗');
        $this->error('  ║                                                               ║');
        $this->error('  ║   ⛔ COMMAND PROHIBITED: migrate:fresh                        ║');
        $this->error('  ║                                                               ║');
        $this->error('  ║   This command would delete ALL tables and data.              ║');
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
