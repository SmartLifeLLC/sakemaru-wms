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
        Schema::connection($this->connection)->create('wms_order_jx_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('設定名');
            $table->string('van_center', 50)->nullable()->comment('VANセンター');
            $table->string('client_id', 50)->nullable()->comment('クライアントID');
            $table->string('server_id', 50)->nullable()->comment('サーバーID');
            $table->string('endpoint_url', 255)->nullable()->comment('接続先URL');
            $table->boolean('is_basic_auth')->default(false)->comment('Basic認証使用フラグ');
            $table->string('basic_user_id', 100)->nullable()->comment('Basic認証ユーザーID');
            $table->string('basic_user_pw', 255)->nullable()->comment('Basic認証パスワード（暗号化）');
            $table->string('jx_from', 50)->nullable()->comment('JXエンベロープ送信元');
            $table->string('jx_to', 50)->nullable()->comment('JXエンベロープ送信先');
            $table->string('ssl_certification_file', 255)->nullable()->comment('SSL証明書ファイルパス');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_jx_settings');
    }
};
