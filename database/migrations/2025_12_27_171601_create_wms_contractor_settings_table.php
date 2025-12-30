<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 発注先設定テーブル（WMS側で管理）
 *
 * sakemaru本家のcontractorsテーブルは変更せず、
 * WMS固有の送信設定をこのテーブルで管理する。
 * contractor_id は 1:1 の関係（UNIQUE制約）
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_contractor_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->unique()->comment('発注先ID（1:1）');

            // 送信方式
            $table->enum('transmission_type', ['JX_FINET', 'FTP', 'MANUAL_CSV'])
                ->default('MANUAL_CSV')
                ->comment('送信方式');

            // JX設定参照
            $table->unsignedBigInteger('wms_order_jx_setting_id')->nullable()->comment('JX接続設定ID');

            // FTP設定参照
            $table->unsignedBigInteger('wms_order_ftp_setting_id')->nullable()->comment('FTP接続設定ID');

            // フォーマット戦略クラス名
            $table->string('format_strategy_class', 255)->nullable()->comment('フォーマット戦略クラス');

            // 送信時刻
            $table->string('transmission_time', 5)->nullable()->comment('送信時刻');

            // 送信曜日（JSON配列）
            $table->json('transmission_days')->nullable()->comment('送信曜日');

            // 自動送信フラグ
            $table->boolean('is_auto_transmission')->default(false)->comment('自動送信フラグ');

            $table->timestamps();

            $table->index('contractor_id', 'idx_contractor');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_contractor_settings');
    }
};
