<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Modifications per 02_picking_2.md specification:
     * 1. Remove earning_id (no longer needed at task level)
     * 2. Add shipment_date (出荷日)
     *
     * Note: Grouping by delivery course (配送コース別) is handled through
     * wave -> wave_setting -> delivery_course relationship
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            // Remove earning_id - earnings are now tracked at item result level
            $table->dropColumn('earning_id');

            // Add shipment_date
            $table->date('shipment_date')
                ->after('warehouse_id')
                ->nullable()
                ->comment('出荷予定日');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            // Restore earning_id
            $table->unsignedBigInteger('earning_id')
                ->after('warehouse_id')
                ->comment('対応伝票 (earnings.id)');

            // Remove shipment_date
            $table->dropColumn('shipment_date');
        });
    }
};
