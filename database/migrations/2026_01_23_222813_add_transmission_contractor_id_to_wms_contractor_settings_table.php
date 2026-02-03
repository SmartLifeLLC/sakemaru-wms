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
        Schema::connection('sakemaru')->table('wms_contractor_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('transmission_contractor_id')
                ->nullable()
                ->after('contractor_id')
                ->comment('発注データ送信先の発注先ID（NULLの場合は自身の設定を使用）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropColumn('transmission_contractor_id');
        });
    }
};
