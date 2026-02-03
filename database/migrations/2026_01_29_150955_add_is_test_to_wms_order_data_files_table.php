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
        Schema::table('wms_order_data_files', function (Blueprint $table) {
            // テストファイルかどうかを示すフラグ
            $table->boolean('is_test')->default(false)->after('status')
                ->comment('テストファイルフラグ');

            // インデックス追加
            $table->index('is_test', 'idx_is_test');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_order_data_files', function (Blueprint $table) {
            $table->dropIndex('idx_is_test');
            $table->dropColumn('is_test');
        });
    }
};
