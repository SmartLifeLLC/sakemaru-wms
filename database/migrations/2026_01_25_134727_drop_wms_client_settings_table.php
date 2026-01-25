<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wms_client_settingsテーブルを廃止
 *
 * 顧客別設定はEnumベース（EWMSClient）に移行。
 * 理由: 顧客別に同じ対応はほぼないため毎回カスタム実装になり、DBでの管理が無意味。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_client_settings');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->create('wms_client_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->unique()->comment('クライアントID');
            $table->string('order_file_generator_class', 255)->nullable()->comment('発注ファイル生成クラス（FQCN）');
            $table->timestamps();
        });
    }
};
