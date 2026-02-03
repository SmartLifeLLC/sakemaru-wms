<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            // 仕入先ID（contractor_idの後に追加）
            $table->unsignedBigInteger('supplier_id')->nullable()->after('contractor_id');
            // 仕入単価（発注時点の価格を履歴として保存）
            $table->decimal('purchase_unit_price', 12, 2)->nullable()->after('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn(['supplier_id', 'purchase_unit_price']);
        });
    }
};
