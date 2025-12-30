<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transmission_days JSON を曜日ごとのbooleanカラムに変更
 * 検索可能にするため
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            // JSONカラムを削除
            $table->dropColumn('transmission_days');

            // 曜日ごとのbooleanカラムを追加
            $table->boolean('is_transmission_sun')->default(false)->after('transmission_time')->comment('日曜送信');
            $table->boolean('is_transmission_mon')->default(false)->after('is_transmission_sun')->comment('月曜送信');
            $table->boolean('is_transmission_tue')->default(false)->after('is_transmission_mon')->comment('火曜送信');
            $table->boolean('is_transmission_wed')->default(false)->after('is_transmission_tue')->comment('水曜送信');
            $table->boolean('is_transmission_thu')->default(false)->after('is_transmission_wed')->comment('木曜送信');
            $table->boolean('is_transmission_fri')->default(false)->after('is_transmission_thu')->comment('金曜送信');
            $table->boolean('is_transmission_sat')->default(false)->after('is_transmission_fri')->comment('土曜送信');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            // booleanカラムを削除
            $table->dropColumn([
                'is_transmission_sun',
                'is_transmission_mon',
                'is_transmission_tue',
                'is_transmission_wed',
                'is_transmission_thu',
                'is_transmission_fri',
                'is_transmission_sat',
            ]);

            // JSONカラムを復元
            $table->json('transmission_days')->nullable()->after('transmission_time')->comment('送信曜日');
        });
    }
};
