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
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->string('order_file_generator', 50)
                ->nullable()
                ->after('add_zero_record')
                ->comment('発注ファイル生成クラス（EOrderFileGenerator enum値）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn('order_file_generator');
        });
    }
};
