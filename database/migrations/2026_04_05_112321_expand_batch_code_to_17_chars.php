<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * batch_code を char(14) → char(17) に拡張
     * 末尾に倉庫ID（3桁ゼロ埋め）を付与するため
     * 既存データ（14文字）はそのまま保持される
     */
    public function up(): void
    {
        $tables = [
            'wms_auto_order_job_controls',
            'wms_order_candidates',
            'wms_stock_transfer_candidates',
            'wms_order_calculation_logs',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('batch_code', 17)->change();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'wms_auto_order_job_controls',
            'wms_order_candidates',
            'wms_stock_transfer_candidates',
            'wms_order_calculation_logs',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('batch_code', 14)->change();
            });
        }
    }
};