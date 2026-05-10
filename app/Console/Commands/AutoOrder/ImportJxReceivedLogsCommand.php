<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\IncomingReceiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportJxReceivedLogsCommand extends Command
{
    protected $signature = 'wms:incoming-import-jx-logs
                            {--log-id= : 取り込むJX受信ログID}
                            {--latest : 最新の未取込JX受信ログを1件取り込む}
                            {--all : 未取込JX受信ログをすべて取り込む}
                            {--from= : 取込対象の開始日時}
                            {--to= : 取込対象の終了日時}
                            {--match : 取込後に入荷予定と照合する}
                            {--apply : 照合済みなら入荷予定へ適用する}
                            {--dry-run : 対象確認のみで更新しない}
                            {--yes : 確認なしで実行}';

    protected $description = 'JX受信ログに保存済みの原本を受信データへ取り込む';

    public function handle(IncomingReceiveService $service): int
    {
        $logs = $this->resolveLogs();

        if ($logs->isEmpty()) {
            $this->error('取込対象のJX受信ログがありません。');

            return self::FAILURE;
        }

        $this->table(
            ['ログID', 'JX設定', 'Message ID', 'サイズ', 'ファイル', '受信日時'],
            $logs->map(fn ($log): array => [
                $log->id,
                $log->jx_setting_id,
                $log->message_id,
                $log->data_size ?? '-',
                $log->file_path,
                $log->created_at,
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run のため、取込は行いません。');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('表示したJX受信ログを受信データへ取り込みますか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $imported = 0;
        $matched = 0;
        $applied = 0;
        $errors = 0;

        foreach ($logs as $log) {
            try {
                [$disk, $path] = $this->parseStoragePath($log->file_path);
                if (! Storage::disk($disk)->exists($path)) {
                    throw new \RuntimeException("原本ファイルが存在しません: {$log->file_path}");
                }

                $content = Storage::disk($disk)->get($path);
                $filename = basename($path);

                $file = $service->parseJxData($content, $filename, null, [
                    'raw_file_path' => $log->file_path,
                    'raw_file_size' => strlen($content),
                    'raw_sha256' => hash('sha256', $content),
                    'received_message_id' => $log->message_id,
                    'confirm_status' => 'SENT',
                    'confirmed_at' => $log->transmitted_at ?? $log->created_at,
                ]);
                $imported++;

                $this->line("ログID {$log->id}: 取込ID {$file->id} / 伝票{$file->parsed_slip_count}件 / 明細{$file->parsed_detail_count}件");

                if ($this->option('match') || $this->option('apply')) {
                    $match = $service->matchWithSchedules($file);
                    $matched += $match['matched'];
                    $file->refresh();
                    $this->line("  照合: 一致{$match['matched']} / 欠品{$match['shortage']} / 未一致{$match['unmatched']}");
                }

                if ($this->option('apply') && $file->status === 'MATCHED') {
                    $result = $service->applyMatched($file);
                    $applied += $result['applied'];
                    $errors += count($result['errors']);
                    $this->line("  適用: {$result['applied']}件 / エラー: ".count($result['errors']).'件');
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("ログID {$log->id}: {$e->getMessage()}");
            }
        }

        $this->info("完了: 取込{$imported}件 / 照合一致{$matched}件 / 適用{$applied}件 / エラー{$errors}件");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveLogs(): Collection
    {
        $logId = $this->option('log-id');
        $latest = (bool) $this->option('latest');
        $all = (bool) $this->option('all');

        $specified = collect([$logId !== null, $latest, $all])->filter()->count();
        if ($specified !== 1) {
            $this->error('--log-id、--latest、--all のいずれか1つだけを指定してください。');

            return collect();
        }

        $query = DB::connection('sakemaru')
            ->table('wms_jx_transmission_logs as l')
            ->where('l.direction', 'receive')
            ->where('l.operation_type', 'GetDocument')
            ->where('l.status', 'success')
            ->whereNotNull('l.file_path')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('wms_incoming_received_files as f')
                    ->whereColumn('f.received_message_id', 'l.message_id');
            })
            ->orderByDesc('l.id');

        if ($logId !== null) {
            return $query->where('l.id', (int) $logId)->get();
        }

        if ($this->option('from')) {
            $query->where('l.created_at', '>=', $this->option('from'));
        }

        if ($this->option('to')) {
            $query->where('l.created_at', '<=', $this->option('to'));
        }

        if ($latest) {
            return $query->limit(1)->get();
        }

        return $query->get();
    }

    private function parseStoragePath(string $filePath): array
    {
        if (str_contains($filePath, ':')) {
            [$disk, $path] = explode(':', $filePath, 2);

            return [$disk, ltrim($path, '/')];
        }

        return ['s3', $filePath];
    }
}
