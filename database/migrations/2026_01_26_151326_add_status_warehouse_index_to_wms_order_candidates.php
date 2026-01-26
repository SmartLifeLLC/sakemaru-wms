<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::table('wms_order_candidates', function (Blueprint $table) {
            // status + warehouse_id の複合インデックス
            // PENDINGの倉庫別一覧取得の高速化用
            $table->index(
                ['status', 'warehouse_id'],
                'idx_status_warehouse'
            );
        });
    }

    public function down(): void
    {
        Schema::table('wms_order_candidates', function (Blueprint $table) {
            $table->dropIndex('idx_status_warehouse');
        });
    }
};
