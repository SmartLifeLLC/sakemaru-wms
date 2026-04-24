<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
            $table->boolean('is_mail_order')->default(false)->after('total_quantity');
        });

        DB::connection('sakemaru')->statement('
            UPDATE wms_order_data_files f
            JOIN wms_contractor_settings cs ON f.contractor_id = cs.contractor_id
            SET f.is_mail_order = CASE
                WHEN cs.order_mail IS NOT NULL AND cs.order_mail != \'\' THEN 1
                ELSE 0
            END
        ');
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropColumn('is_mail_order');
        });
    }
};
