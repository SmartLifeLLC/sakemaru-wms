<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_count_round')) {
            Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table): void {
                $table->unsignedTinyInteger('inventory_adjustment_count_round')->nullable()->comment('最終確定したカウント回');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table): void {
            $table->dropColumn('inventory_adjustment_count_round');
        });
    }
};
