<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * システム初期化シーダー
 *
 * WMSシステムの初期設定に必要なシーダーを実行します。
 * 新規環境構築時やシステム初期化時に使用します。
 *
 * 実行方法:
 *   php artisan db:seed --class=InitSystemSeeder
 */
class InitSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('WMS システム初期化シーダーを実行します');
        $this->command->info('========================================');

        // 初期ピッカー生成
        $this->call(InitialPickerSeeder::class);

        // 発注先時刻初期設定
        $this->call(ContractorInitSeeder::class);

        // 発注先メールテンプレート初期設定
        $this->call(ContractorMailSettingSeeder::class);

        // 月別発注点初期データ
        $this->call(MonthlySafetyStockInitSeeder::class);

        // 自動発注対象の初期設定（CSVベース）
        $this->call(AutoOrderCandidateInitSeeder::class);

        // 今後追加予定の初期設定
        // $this->call(InitialPickingAreaSeeder::class);
        // $this->call(InitialWaveSettingSeeder::class);
        // $this->call(InitialWarehouseSettingSeeder::class);

        $this->command->info('========================================');
        $this->command->info('システム初期化が完了しました');
        $this->command->info('========================================');
    }
}
