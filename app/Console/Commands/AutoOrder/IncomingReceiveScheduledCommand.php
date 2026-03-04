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

            // 当日すでに受信済みかチェック
            $alreadyReceived = WmsIncomingReceivedFile::where('contractor_id', $setting->contractor_id)
                ->whereDate('created_at', $now->toDateString())
                ->exists();

            if ($alreadyReceived) {
                $this->line("  {$contractorName} → スキップ（当日受信済み）");
                $skipped++;

                continue;
            }

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

    /**
     * JXデータを受信・パース・照合
     */
    private function receiveJx(WmsContractorSetting $setting, IncomingReceiveService $service): void
    {
        $jxClient = new JxClient($setting->jxSetting);

        // GetDocument実行
        $result = $jxClient->getDocument();

        if ($result->failed()) {
            throw new \RuntimeException("JX GetDocument失敗: {$result->error}");
        }

        if (! $result->hasDocument()) {
            $this->line('    → 受信データなし');

            return;
        }

        // データ取得
        $compressType = $result->getCompressType();
        $data = $result->getDecodedAndDecompressedData(
            decompress: ! empty($compressType)
        );

        if (empty($data)) {
            $this->line('    → 受信データが空です');

            return;
        }

        // パース
        $filename = "jx_receive_{$setting->contractor_id}_" . now()->format('YmdHis') . '.dat';
        $file = $service->parseJxData($data, $filename, $setting->contractor_id);

        $this->line("    → パース完了: 伝票{$file->parsed_slip_count}件 / 明細{$file->parsed_detail_count}件");

        // 自動照合
        $matchResult = $service->matchWithSchedules($file);

        $this->line("    → 照合完了: 一致{$matchResult['matched']}件 / 欠品{$matchResult['shortage']}件 / 未一致{$matchResult['unmatched']}件");

        // 受信確認 (ConfirmDocument)
        $receivedMessageId = $result->getReceivedMessageId();
        if ($receivedMessageId) {
            $confirmResult = $jxClient->confirmDocument($receivedMessageId);
            if ($confirmResult->failed()) {
                Log::warning('[IncomingReceiveScheduled] ConfirmDocument失敗', [
                    'contractor_id' => $setting->contractor_id,
                    'message_id' => $receivedMessageId,
                    'error' => $confirmResult->error,
                ]);
            }
        }
    }
}
