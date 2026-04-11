<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->integer('shipped_quantity')->default(0)->after('expected_quantity')
                ->comment('出荷実績数（メーカーからの出荷実績）');

            $table->decimal('unit_price', 12, 2)->nullable()->after('shortage_quantity')
                ->comment('仕入自社バラ単価');
            $table->decimal('case_price', 12, 2)->nullable()->after('unit_price')
                ->comment('仕入自社ケース単価');
            $table->decimal('partner_unit_price', 12, 2)->nullable()->after('case_price')
                ->comment('仕入先バラ単価（出荷実績から取込）');
            $table->decimal('partner_case_price', 12, 2)->nullable()->after('partner_unit_price')
                ->comment('仕入先ケース単価（出荷実績から取込）');
            $table->string('price_type', 10)->nullable()->after('partner_case_price')
                ->comment('単価タイプ: CASE or PIECE');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'shipped_quantity',
                'unit_price',
                'case_price',
                'partner_unit_price',
                'partner_case_price',
                'price_type',
            ]);
        });
    }
};
