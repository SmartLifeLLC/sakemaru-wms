<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn('locations', 'is_disabled')) {
            DB::statement('ALTER TABLE `locations` ADD `is_disabled` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('locations', 'is_disabled')) {
            DB::statement('ALTER TABLE `locations` DROP COLUMN `is_disabled`');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->exists();
    }
};
