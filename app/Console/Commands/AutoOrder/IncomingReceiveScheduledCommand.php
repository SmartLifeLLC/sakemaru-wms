<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsContractorSetting;
use App\Models\WmsIncomingReceivedFile;
use App\Services\AutoOrder\IncomingReceiveService;
use App\Services\JX\JxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IncomingReceiveScheduledCommand extends Command
{
    protected $signature = 'wms:incoming-receive-scheduled';

    protected $description = '仕入先別スケジュールに基づく入荷データ自動受信';

    public function handle(): int
    {
        $now = now();
        $currentTime = $now->format('H:i');
        $dayOfWeek = (int) $now->format('w'); // 0=日, 1=月, ..., 6=土

        $this->info("=== 入荷データ自動受信スケジューラー ({$currentTime}) ===");

        // 受信対象の仕入先を取得
        $settings = WmsContractorSetting::query()
            ->where('is_receive_enabled', true)
            ->whereNotNull('receive_time')
            ->where('receive_time', '<=', $currentTime)
            ->whereNotNull('wms_order_jx_setting_id')
            ->with(['contractor', 'jxSetting'])
            ->get()
            ->filter(fn ($s) => $s->shouldReceiveOn($dayOfWeek));

        if ($settings->isEmpty()) {
            $this->info('対象の仕入先はありません');

            return self::SUCCESS;
        }

        $this->info("対象仕入先: {$settings->count()}件");

        $received = 0;
        $skipped = 0;
        $errors = 0;

        $service = app(IncomingReceiveService::class);

        foreach ($settings as $setting) {
            $contractorName = $setting->contractor?->name ?? "ID:{$setting->contractor_id}";

            try {
                $this->line("  {$contractorName} → 受信開始...");

                if ($setting->receive_format === 'JX' && $setting->jxSetting) {
                    $this->receiveJx($setting, $service);
                    $received++;
                } else {
                    $this->line("    → スキップ（JX設定なし or CSV形式は未対応）");
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->error("  {$contractorName} → エラー: {$e->getMessage()}");
                Log::error('[IncomingReceiveScheduled] 受信エラー', [
                    'contractor_id' => $setting->contractor_id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->info("受信: {$received}件, スキップ: {$skipped}件, エラー: {$errors}件");
        $this->info('=== 完了 ===');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** 無限ループ防止の最大取得回数 */
    private const MAX_RECEIVE_ATTEMPTS = 50;

    /**
     * JXデータを受信・パース・照合（サーバにデータがなくなるまで繰り返し）
     */
    private function receiveJx(WmsContractorSetting $setting, IncomingReceiveService $service): void
    {
        $jxClient = new JxClient($setting->jxSetting);
        $fileCount = 0;

        for ($i = 0; $i < self::MAX_RECEIVE_ATTEMPTS; $i++) {
            $result = $jxClient->getDocument();

            if ($result->failed()) {
                throw new \RuntimeException("JX GetDocument失敗: {$result->error}");
            }

            if (! $result->hasDocument()) {
                if ($fileCount === 0) {
                    $this->line('    → 受信データなし');
                } else {
                    $this->line("    → 全{$fileCount}件の受信完了（サーバにデータなし）");
                }

                return;
            }

            $fileCount++;

            // データ取得
            $compressType = $result->getCompressType();
            $data = $result->getDecodedAndDecompressedData(
                decompress: ! empty($compressType)
            );

            if (empty($data)) {
                $this->line("    → [{$fileCount}] 受信データが空です");

                // 空でもConfirmDocumentは送る
                $this->confirmDocument($jxClient, $result, $setting->contractor_id);

                continue;
            }

            // パース
            $filename = "jx_receive_{$setting->contractor_id}_" . now()->format('YmdHis') . "_{$fileCount}.dat";
            $file = $service->parseJxData($data, $filename, $setting->contractor_id);

            $this->line("    → [{$fileCount}] パース完了: 伝票{$file->parsed_slip_count}件 / 明細{$file->parsed_detail_count}件");

            // 自動照合
            $matchResult = $service->matchWithSchedules($file);

            $this->line("    → [{$fileCount}] 照合完了: 一致{$matchResult['matched']}件 / 欠品{$matchResult['shortage']}件 / 未一致{$matchResult['unmatched']}件");

            // 受信確認 (ConfirmDocument)
            $this->confirmDocument($jxClient, $result, $setting->contractor_id);
        }

        $this->warn("    → 最大取得回数（" . self::MAX_RECEIVE_ATTEMPTS . "）に達しました");
    }

    /**
     * 受信確認 (ConfirmDocument) を送信
     */
    private function confirmDocument(JxClient $jxClient, $result, int $contractorId): void
    {
        $receivedMessageId = $result->getReceivedMessageId();
        if ($receivedMessageId) {
            $confirmResult = $jxClient->confirmDocument($receivedMessageId);
            if ($confirmResult->failed()) {
                Log::warning('[IncomingReceiveScheduled] ConfirmDocument失敗', [
                    'contractor_id' => $contractorId,
                    'message_id' => $receivedMessageId,
                    'error' => $confirmResult->error,
                ]);
            }
        }
    }
}
