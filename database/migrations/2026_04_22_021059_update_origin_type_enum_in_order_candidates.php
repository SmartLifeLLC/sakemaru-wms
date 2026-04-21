<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newEnum = "'AUTO_SAFETY_STOCK','AUTO_SALES_BASED','MANUAL_SAFETY_STOCK','MANUAL_SALES_BASED','AUTO','USER','DIST'";

        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_candidates MODIFY COLUMN origin_type ENUM({$newEnum}) NULL"
        );
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_stock_transfer_candidates MODIFY COLUMN origin_type ENUM({$newEnum}) NULL"
        );
    }

    public function down(): void
    {
        $oldEnum = "'AUTO','USER','DIST'";

        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_candidates MODIFY COLUMN origin_type ENUM({$oldEnum}) NULL"
        );
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_stock_transfer_candidates MODIFY COLUMN origin_type ENUM({$oldEnum}) NULL"
        );
    }
};
