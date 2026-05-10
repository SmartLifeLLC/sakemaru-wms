<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsIncomingReceivedFile;
use App\Services\AutoOrder\IncomingReceiveService;
use Illuminate\Console\Command;

class ApplyIncomingReceivedDataCommand extends Command
{
    protected $signature = 'wms:incoming-apply-received
                            {--file= : 適用する受信ファイルID}
                            {--latest : 最新の照合済み受信ファイルを適用}
                            {--all-matched : 照合済みの全受信ファイルを適用}
                            {--all-pending : 未照合の全受信ファイルを再照合して、照合済みなら適用}
                            {--match : 適用前に照合を実行}
                            {--dry-run : 対象確認のみで更新しない}
                            {--yes : 確認なしで実行}';

    protected $description = '照合済みのJX入荷受信データを入荷予定へ適用';

    public function handle(IncomingReceiveService $service): int
    {
        $targets = $this->resolveTargets();

        if ($targets->isEmpty()) {
            $this->error('適用対象の受信ファイルがありません。');

            return self::FAILURE;
        }

        if ($this->option('all-pending') && ! $this->option('match')) {
            $this->error('--all-pending は --match と一緒に指定してください。');

            return self::FAILURE;
        }

        $this->table(
            ['ID', '仕入先ID', 'ファイル名', '形式', '状態', 'Confirm', '伝票数', '明細数', '取込日時'],
            $targets->map(fn (WmsIncomingReceivedFile $file): array => [
                $file->id,
                $file->contractor_id ?? '-',
                $file->filename ?? '-',
                $file->format_type,
                $file->status,
                $file->confirm_status ?? '-',
                $file->parsed_slip_count,
                $file->parsed_detail_count,
                optional($file->created_at)->format('Y-m-d H:i:s') ?? '-',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run のため、照合・適用は行いません。');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('表示した受信ファイルを入荷予定へ適用しますか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $totalApplied = 0;
        $totalErrors = 0;

        foreach ($targets as $file) {
            $this->line("受信ファイルID {$file->id} を処理します...");

            if ($this->option('match')) {
                $match = $service->matchWithSchedules($file);
                $file->refresh();
                $this->line("  照合: 一致{$match['matched']} / 欠品{$match['shortage']} / 未一致{$match['unmatched']}");
            }

            if ($file->status !== 'MATCHED') {
                $this->warn("  スキップ: 状態が MATCHED ではありません（現在: {$file->status}）");

                continue;
            }

            $result = $service->applyMatched($file);
            $totalApplied += $result['applied'];
            $totalErrors += count($result['errors']);

            $this->line("  適用: {$result['applied']}件 / エラー: ".count($result['errors']).'件');

            foreach ($result['errors'] as $error) {
                $this->error("    伝票 {$error['slip_number']}: {$error['error']}");
            }
        }

        $this->info("完了: 適用 {$totalApplied}件 / エラー {$totalErrors}件");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveTargets()
    {
        $fileId = $this->option('file');
        $latest = (bool) $this->option('latest');
        $allMatched = (bool) $this->option('all-matched');
        $allPending = (bool) $this->option('all-pending');

        $specified = collect([$fileId !== null, $latest, $allMatched, $allPending])->filter()->count();
        if ($specified !== 1) {
            $this->error('--file、--latest、--all-matched、--all-pending のいずれか1つだけを指定してください。');

            return collect();
        }

        $query = WmsIncomingReceivedFile::query()
            ->where('format_type', 'JX')
            ->orderByDesc('id');

        if ($fileId !== null) {
            return $query->whereKey((int) $fileId)->get();
        }

        if ($latest) {
            return $query->where('status', 'MATCHED')->limit(1)->get();
        }

        if ($allPending) {
            return $query->where('status', 'PENDING')->get();
        }

        return $query->where('status', 'MATCHED')->get();
    }
}
