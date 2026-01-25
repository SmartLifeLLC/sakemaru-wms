<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 顧客別設定テーブル
 *
 * 導入先（クライアント）別に実装クラスを紐付ける。
 * 発注ファイル生成など、クライアントごとに異なる実装を切り替える。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_client_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->unique()->comment('クライアントID');

            // 実装クラス紐付け
            $table->string('order_file_generator_class', 255)->nullable()->comment('発注ファイル生成クラス（FQCN）');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_client_settings');
    }
};
