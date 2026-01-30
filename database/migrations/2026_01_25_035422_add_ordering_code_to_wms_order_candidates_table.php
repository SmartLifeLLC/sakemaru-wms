<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            // 発注コード（item_search_informationのis_used_for_ordering=trueのコード）
            // 13桁にゼロパディングして保存
            $table->string('ordering_code', 13)->nullable()->after('contractor_id')
                ->comment('発注コード（JAN/ITF/OTHER等、13桁ゼロパディング）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn('ordering_code');
        });
    }
};
