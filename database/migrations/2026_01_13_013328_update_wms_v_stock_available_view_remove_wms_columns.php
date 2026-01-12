<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * wms_reserved_qty, wms_picking_qty を廃止し、available_quantity をそのまま使用
     */
    public function up(): void
    {
        if (Schema::connection('sakemaru')->hasTable('real_stocks')) {
            DB::connection('sakemaru')->statement('
                CREATE OR REPLACE VIEW wms_v_stock_available AS
                SELECT
                    rs.id AS real_stock_id,
                    rs.client_id,
                    rs.warehouse_id,
                    rs.stock_allocation_id,
                    rs.item_id,
                    rs.location_id,
                    rs.expiration_date,
                    rs.purchase_id,
                    rs.price AS unit_cost,
                    rs.current_quantity,
                    rs.reserved_quantity,
                    rs.available_quantity,
                    rs.available_quantity AS available_for_wms,
                    COALESCE(rs.wms_lock_version, 0) AS wms_lock_version,
                    rs.created_at
                FROM real_stocks rs
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('sakemaru')->hasTable('real_stocks')) {
            DB::connection('sakemaru')->statement('
                CREATE OR REPLACE VIEW wms_v_stock_available AS
                SELECT
                    rs.id AS real_stock_id,
                    rs.client_id,
                    rs.warehouse_id,
                    rs.stock_allocation_id,
                    rs.item_id,
                    rs.expiration_date,
                    rs.purchase_id,
                    rs.price AS unit_cost,
                    rs.current_quantity,
                    GREATEST(rs.available_quantity - COALESCE(rs.wms_reserved_qty, 0) - COALESCE(rs.wms_picking_qty, 0), 0) AS available_for_wms,
                    COALESCE(rs.wms_reserved_qty, 0) AS wms_reserved_qty,
                    COALESCE(rs.wms_picking_qty, 0) AS wms_picking_qty,
                    COALESCE(rs.wms_lock_version, 0) AS wms_lock_version,
                    rs.created_at
                FROM real_stocks rs
            ');
        }
    }
};
