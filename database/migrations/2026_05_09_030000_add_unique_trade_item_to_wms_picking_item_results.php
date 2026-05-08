<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $duplicates = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->select('trade_item_id', DB::raw('COUNT(*) as count'))
            ->groupBy('trade_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicates) {
            throw new RuntimeException(
                "Cannot add uq_wms_picking_item_results_trade_item_id: duplicate trade_item_id {$duplicates->trade_item_id} exists."
            );
        }

        $indexes = DB::connection('sakemaru')
            ->select("SHOW INDEX FROM wms_picking_item_results WHERE Key_name = 'uq_wms_picking_item_results_trade_item_id'");

        if (empty($indexes)) {
            Schema::connection('sakemaru')->table('wms_picking_item_results', function ($table) {
                $table->unique('trade_item_id', 'uq_wms_picking_item_results_trade_item_id');
            });
        }
    }

    public function down(): void
    {
        $indexes = DB::connection('sakemaru')
            ->select("SHOW INDEX FROM wms_picking_item_results WHERE Key_name = 'uq_wms_picking_item_results_trade_item_id'");

        if (! empty($indexes)) {
            Schema::connection('sakemaru')->table('wms_picking_item_results', function ($table) {
                $table->dropUnique('uq_wms_picking_item_results_trade_item_id');
            });
        }
    }
};
