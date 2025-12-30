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
        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            // client_id を jx_client_id にリネーム
            $table->renameColumn('client_id', 'jx_client_id');
        });

        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            // 送信元情報
            $table->string('sender_trading_code', 12)->nullable()->after('jx_client_id')->comment('送信元統一取引先コード');
            $table->string('sender_station_code', 6)->nullable()->after('sender_trading_code')->comment('送信元ステーションコード');
            $table->string('sender_name', 15)->nullable()->after('sender_station_code')->comment('送信元企業名（半角カナ）');
            $table->string('sender_office_name', 10)->nullable()->after('sender_name')->comment('送信元事業所名（半角カナ）');

            // 送信先情報
            $table->string('receiver_trading_code', 12)->nullable()->after('sender_office_name')->comment('送信先統一取引先コード');
            $table->string('receiver_station_code', 6)->nullable()->after('receiver_trading_code')->comment('送信先ステーションコード');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sender_trading_code',
                'sender_station_code',
                'sender_name',
                'sender_office_name',
                'receiver_trading_code',
                'receiver_station_code',
            ]);
        });

        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->renameColumn('jx_client_id', 'client_id');
        });
    }
};
