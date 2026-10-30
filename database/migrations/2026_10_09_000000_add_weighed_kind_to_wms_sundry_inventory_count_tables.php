<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 棚卸し（雑貨）に「量り売り」の種類を追加する。
 *
 * 量り売り焼酎は 100ml 単位の数量で棚卸しし、実棚はカメとQT（予備）の2欄で入力する。
 * 前回棚卸日の翌日からの売上数量（期間売上）も明細に持つ。
 * 既存の雑貨の棚卸しは kind = sundry のまま動きを変えない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_sundry_inventory_counts', function (Blueprint $table) {
            $table->string('kind', 20)->default('sundry')->after('count_no')->comment('sundry: 雑貨 / weighed: 量り売り');
            $table->date('sales_from_date')->nullable()->after('theory_updated_at')->comment('量り売り: 期間売上の開始日（前回棚卸日の翌日）');
            $table->index('kind', 'wms_sundry_counts_kind_idx');
        });

        Schema::connection('sakemaru')->table('wms_sundry_inventory_count_items', function (Blueprint $table) {
            $table->unsignedInteger('display_order')->nullable()->after('is_additional')->comment('量り売り: 出力順');
            $table->decimal('counted_quantity_jar', 15, 3)->nullable()->after('counted_quantity')->comment('量り売り: 実棚数（カメ）');
            $table->decimal('counted_quantity_reserve', 15, 3)->nullable()->after('counted_quantity_jar')->comment('量り売り: 実棚数（QT）');
            $table->decimal('period_sales_quantity', 15, 3)->nullable()->after('difference_amount')->comment('量り売り: 期間売上数量');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_sundry_inventory_count_items', function (Blueprint $table) {
            $table->dropColumn(['display_order', 'counted_quantity_jar', 'counted_quantity_reserve', 'period_sales_quantity']);
        });

        Schema::connection('sakemaru')->table('wms_sundry_inventory_counts', function (Blueprint $table) {
            $table->dropIndex('wms_sundry_counts_kind_idx');
            $table->dropColumn(['kind', 'sales_from_date']);
        });
    }
};
