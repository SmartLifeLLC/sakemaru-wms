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
     * wms_reserved_qty と wms_picking_qty を廃止
     * - 引当管理は reserved_quantity（Sakemaru側で管理）に統一
     * - ピッキング中の状態は earnings.picking_status で管理
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('real_stocks', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('real_stocks', 'wms_reserved_qty')) {
                $table->dropColumn('wms_reserved_qty');
            }
            if (Schema::connection('sakemaru')->hasColumn('real_stocks', 'wms_picking_qty')) {
                $table->dropColumn('wms_picking_qty');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('real_stocks', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('real_stocks', 'wms_reserved_qty')) {
                $table->integer('wms_reserved_qty')->default(0)->comment('WMS reserved quantity')->after('available_quantity');
            }
            if (! Schema::connection('sakemaru')->hasColumn('real_stocks', 'wms_picking_qty')) {
                $table->integer('wms_picking_qty')->default(0)->comment('Currently being picked quantity')->after('wms_reserved_qty');
            }
        });
    }
};
