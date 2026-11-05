<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_date')) {
            Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table): void {
                $table->date('inventory_adjustment_date')->nullable()->comment('最終確定時に指定した棚卸し伝票日付');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table): void {
            $table->dropColumn('inventory_adjustment_date');
        });
    }
};
