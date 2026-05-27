<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALL_ALLOCATABLE_QUANTITY_FLAGS = 7; // CASE | PIECE | CARTON

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
                    ->orWhere('available_quantity_flags', 8);
            })
            ->update([
                'available_quantity_flags' => self::ALL_ALLOCATABLE_QUANTITY_FLAGS,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time emergency data correction. Previous flags are not restored.
    }
};
