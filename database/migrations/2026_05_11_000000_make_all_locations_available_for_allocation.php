<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GENERAL_ALLOCATABLE_QUANTITY_FLAGS = 3; // CASE | PIECE

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('locations')) {
            return;
        }

        if (! Schema::connection('sakemaru')->hasColumn('locations', 'available_quantity_flags')) {
            return;
        }

        DB::connection('sakemaru')
            ->table('locations')
            ->where(function ($query) {
                $query
                    ->whereNull('available_quantity_flags')
                    ->orWhere('available_quantity_flags', '!=', self::GENERAL_ALLOCATABLE_QUANTITY_FLAGS);
            })
            ->update([
                'available_quantity_flags' => self::GENERAL_ALLOCATABLE_QUANTITY_FLAGS,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time data correction. Previous per-location flags are not restored on rollback.
    }
};
