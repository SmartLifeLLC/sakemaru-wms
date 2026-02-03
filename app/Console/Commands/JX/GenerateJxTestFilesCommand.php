<?php

namespace App\Console\Commands\JX;

use App\Models\WmsOrderJxSetting;
use App\Services\JX\JxTestFileGenerator;
use Illuminate\Console\Command;

/**
 * JX送信テスト用ファイル生成コマンド
 *
 * テストパターン:
 * - empty: 空ファイル（発注データなし）
 * - full: 全商品発注ファイル
 * - aggregated: 送信先集約テストファイル
 */
class GenerateJxTestFilesCommand extends Command
{
    protected $signature = 'wms:generate-jx-test-files
                            {--pattern=full : テストパターン (empty, full, aggregated, all)}
                            {--jx-setting= : JX設定ID（省略時は全JX設定）}
                            {--warehouse= : 倉庫ID（省略時はデフォルト倉庫）}
                            {--max-items=50 : 最大商品数（0またはallで全件）}
                            {--transmit : 生成後にJX送信を実行}
                            {--dry-run : ファイル生成のみ（保存しない）}
                            {--list : JX設定一覧を表示}';

    protected $description = 'JX送信テスト用ファイルを生成（オプションで送信も実行）';

    public function handle(JxTestFileGenerator $generator): int
    {
        // JX設定一覧表示モード
        if ($this->option('list')) {
            return $this->listJxSettings();
        }

        $pattern = $this->option('pattern');
        $jxSettingId = $this->option('jx-setting');
        $warehouseId = $this->option('warehouse');
        $maxItemsOption = $this->option('max-items');
        // 0 または 'all' で全件指定
        $maxItems = ($maxItemsOption === '0' || $maxItemsOption === 'all') ? null : (int) $maxItemsOption;
        $transmit = $this->option('transmit');
        $dryRun = $this->option('dry-run');

        // JX設定の取得
        if ($jxSettingId) {
            $jxSettings = WmsOrderJxSetting::where('id', $jxSettingId)
                ->where('is_active', true)
                ->get();

            if ($jxSettings->isEmpty()) {
                $this->error("JX設定 ID={$jxSettingId} が見つかりません");

                return self::FAILURE;
            }
        } else {
            $jxSettings = WmsOrderJxSetting::where('is_active', true)->get();

            if ($jxSettings->isEmpty()) {
                $this->error('有効なJX設定がありません');

                return self::FAILURE;
            }
        }

        $this->info('=== JX送信テストファイル生成 ===');
        $this->newLine();

        // 各JX設定に対してファイル生成
        $results = [];
        foreach ($jxSettings as $setting) {
            $this->info("【{$setting->name}】 (ID: {$setting->id})");

            try {
                $settingResults = $this->generateFilesForSetting(
                    $generator,
                    $setting,
                    $pattern,
                    $warehouseId ? (int) $warehouseId : null,
                    $maxItems,
                    $dryRun
                );

                $results = array_merge($results, $settingResults);

                // 送信オプションが指定されている場合
                if ($transmit && ! $dryRun) {
                    foreach ($settingResults as $result) {
                        $this->transmitFile($generator, $result);
                    }
                }

            } catch (\Exception $e) {
                $this->error("  エラー: {$e->getMessage()}");
            }

            $this->newLine();
        }

        // サマリー表示
        $this->displaySummary($results);

        // 確認手順の表示
        if (! $dryRun && ! empty($results)) {
            $this->displayVerificationSteps();
        }

        return self::SUCCESS;
    }

    /**
     * JX設定一覧を表示
     */
    private function listJxSettings(): int
    {
        $settings = WmsOrderJxSetting::all();

        if ($settings->isEmpty()) {
            $this->warn('JX設定が登録されていません');

            return self::SUCCESS;
        }

        $this->info('=== JX設定一覧 ===');
        $this->newLine();

        $rows = $settings->map(fn ($s) => [
            $s->id,
            $s->name,
            $s->sender_station_code,
            $s->receiver_station_code,
            $s->endpoint_url,
            $s->is_active ? 'YES' : 'NO',
        ]);

        $this->table(
            ['ID', '名前', '送信元', '送信先', 'エンドポイント', '有効'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * 指定パターンでファイル生成
     */
    private function generateFilesForSetting(
        JxTestFileGenerator $generator,
        WmsOrderJxSetting $setting,
        string $pattern,
        ?int $warehouseId,
        ?int $maxItems,
        bool $dryRun
    ): array {
        $results = [];
        $patterns = $pattern === 'all' ? ['empty', 'full', 'aggregated'] : [$pattern];

        foreach ($patterns as $p) {
            $this->info("  パターン: {$p}");

            try {
                $result = match ($p) {
                    'empty' => $generator->generateEmptyFile($setting->id),
                    'full' => $generator->generateFullOrderFile($setting->id, $warehouseId, $maxItems),
                    'aggregated' => $generator->generateAggregatedFile($setting->id, $warehouseId, (int) ceil($maxItems / 5)),
                    default => throw new \InvalidArgumentException("不正なパターン: {$p}"),
                };

                if ($dryRun) {
                    $this->info('    [DRY-RUN] ファイル生成シミュレーション完了');
                    $this->info("    レコード数: {$result['record_count']}, 発注数: {$result['order_count']}");
                } else {
                    $this->info("    ファイル: {$result['filename']}");
                    $this->info("    サイズ: {$result['file_size']} bytes");
                    $this->info("    レコード数: {$result['record_count']}, 発注数: {$result['order_count']}");

                    if (isset($result['contractors'])) {
                        $this->info('    発注先: '.implode(', ', $result['contractors']));
                    }
                }

                $results[] = $result;

            } catch (\Exception $e) {
                $this->error("    エラー: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * JX送信を実行
     */
    private function transmitFile(JxTestFileGenerator $generator, array $result): void
    {
        $this->newLine();
        $this->info("  送信中: {$result['filename']}");

        try {
            $transmitResult = $generator->transmitFile($result['jx_setting_id'], $result['content']);

            if ($transmitResult->succeeded()) {
                $this->info("    <fg=green>送信成功</> - MessageID: {$transmitResult->messageId}");
            } else {
                $this->error("    送信失敗: {$transmitResult->errorMessage}");
            }

        } catch (\Exception $e) {
            $this->error("    送信エラー: {$e->getMessage()}");
        }
    }

    /**
     * サマリー表示
     */
    private function displaySummary(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $this->newLine();
        $this->info('=== 生成サマリー ===');

        $rows = collect($results)->map(fn ($r) => [
            $r['jx_setting_name'] ?? 'N/A',
            $r['pattern'],
            $r['filename'] ?? '-',
            number_format($r['file_size'] ?? 0).' bytes',
            $r['record_count'],
            $r['order_count'],
        ]);

        $this->table(
            ['JX設定', 'パターン', 'ファイル名', 'サイズ', 'レコード数', '発注数'],
            $rows
        );
    }

    /**
     * 確認手順を表示
     */
    private function displayVerificationSteps(): void
    {
        $this->newLine();
        $this->info('=== 確認手順 ===');
        $this->newLine();

        $this->line('1. 生成ファイルの確認:');
        $this->line('   ls -la storage/app/private/jx-test/');
        $this->newLine();

        $this->line('2. ファイル内容確認 (Shift_JIS → UTF-8):');
        $this->line('   iconv -f SJIS -t UTF-8 storage/app/private/jx-test/*.dat | head -20');
        $this->newLine();

        $this->line('3. バイナリ確認:');
        $this->line('   hexdump -C storage/app/private/jx-test/*.dat | head -30');
        $this->newLine();

        $this->line('4. JX送信ログ確認:');
        $this->line('   php artisan tinker --execute="\\App\\Models\\WmsJxTransmissionLog::latest()->take(5)->get([\'id\', \'operation_type\', \'status\', \'message_id\', \'created_at\'])"');
        $this->newLine();

        $this->line('5. テストサーバ受信確認 (送信後):');
        $this->line('   ls -la storage/app/private/jx-server/documents/$(date +%Y-%m-%d)/');
        $this->newLine();

        $this->line('6. 受信データ内容確認:');
        $this->line('   iconv -f SJIS -t UTF-8 storage/app/private/jx-server/documents/$(date +%Y-%m-%d)/*.txt');
    }
}
