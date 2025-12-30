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
        Schema::connection($this->connection)->create('wms_order_ftp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('設定名');
            $table->string('host', 255)->comment('ホスト名');
            $table->integer('port')->default(21)->comment('ポート番号');
            $table->string('username', 100)->comment('ユーザー名');
            $table->string('password', 255)->comment('パスワード（暗号化）');
            $table->enum('protocol', ['FTP', 'SFTP', 'FTPS'])->default('FTP')->comment('接続プロトコル');
            $table->boolean('passive_mode')->default(true)->comment('パッシブモード');
            $table->string('remote_directory', 255)->default('/')->comment('リモートディレクトリ');
            $table->string('file_name_pattern', 100)->default('order_{date}.csv')->comment('ファイル名パターン');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_ftp_settings');
    }
};
