<?php

namespace App\Http\Controllers;

use App\Models\WmsJxTransmissionLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JxTransmissionLogController extends Controller
{
    /**
     * ファイルをダウンロード
     */
    public function download(WmsJxTransmissionLog $log): StreamedResponse|Response
    {
        if (empty($log->file_path)) {
            abort(404, 'ファイルパスが記録されていません');
        }

        // ディスクとパスを解析
        [$disk, $path] = $this->parseDiskAndPath($log);

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'ファイルが見つかりません: '.$path);
        }

        // ファイル名を生成
        $extension = $this->getExtensionFromPath($path);
        $filename = $this->generateFilename($log, $extension);

        // Content-Type を決定
        $contentType = $this->getContentType($extension);

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * ディスクとパスを解析
     *
     * ファイルパスの形式:
     * - "s3:path/to/file" → ['s3', 'path/to/file']
     * - "local:path/to/file" → ['local', 'path/to/file']
     * - "path/to/file" (旧形式) → 自動判定
     */
    protected function parseDiskAndPath(WmsJxTransmissionLog $log): array
    {
        $filePath = $log->file_path;

        // ディスクプレフィックスがある場合
        if (preg_match('/^(s3|local):(.+)$/', $filePath, $matches)) {
            return [$matches[1], $matches[2]];
        }

        // 旧形式: ディスクを自動判定
        $disk = $this->determineDiskLegacy($log);

        return [$disk, $filePath];
    }

    /**
     * ストレージディスクを判定（旧形式のファイルパス用）
     */
    protected function determineDiskLegacy(WmsJxTransmissionLog $log): string
    {
        // 送信の場合はlocal（レスポンスファイル）
        if ($log->direction === WmsJxTransmissionLog::DIRECTION_SEND) {
            return 'local';
        }

        // 受信の場合は環境による
        // jx-received で始まる場合は環境に応じて判定
        if (str_starts_with($log->file_path, 'jx-received')) {
            // ローカル環境ではlocalを使用
            if (app()->environment('local', 'testing')) {
                return 'local';
            }

            return 's3';
        }

        // デフォルトはlocal
        return 'local';
    }

    /**
     * ファイル拡張子を取得
     */
    protected function getExtensionFromPath(string $path): string
    {
        $pathInfo = pathinfo($path);

        return $pathInfo['extension'] ?? 'dat';
    }

    /**
     * ダウンロードファイル名を生成
     */
    protected function generateFilename(WmsJxTransmissionLog $log, string $extension): string
    {
        $direction = $log->direction === WmsJxTransmissionLog::DIRECTION_SEND ? 'send' : 'receive';
        $date = $log->transmitted_at?->format('Ymd_His') ?? 'unknown';

        return "jx_{$direction}_{$date}_{$log->id}.{$extension}";
    }

    /**
     * Content-Type を取得
     */
    protected function getContentType(string $extension): string
    {
        return match (strtolower($extension)) {
            'xml' => 'application/xml',
            'txt' => 'text/plain',
            'dat' => 'application/octet-stream',
            default => 'application/octet-stream',
        };
    }
}
