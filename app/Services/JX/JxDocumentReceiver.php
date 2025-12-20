<?php

namespace App\Services\JX;

use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * JXドキュメント受信サービス
 *
 * GetDocument → 保存 → ConfirmDocument の一連の流れを処理
 */
class JxDocumentReceiver
{
    protected JxClient $client;
    protected WmsOrderJxSetting $setting;
    protected string $storageDisk = 's3';
    protected string $storageDirectory = 'jx-received';

    public function __construct(WmsOrderJxSetting $setting)
    {
        $this->setting = $setting;
        $this->client = new JxClient($setting);
    }

    /**
     * ストレージディスクを設定
     */
    public function setStorageDisk(string $disk): self
    {
        $this->storageDisk = $disk;
        return $this;
    }

    /**
     * ストレージディレクトリを設定
     */
    public function setStorageDirectory(string $directory): self
    {
        $this->storageDirectory = $directory;
        return $this;
    }

    /**
     * ドキュメントを受信（全件取得）
     *
     * @return Collection<JxReceivedDocument>
     */
    public function receiveAll(): Collection
    {
        $documents = collect();
        $maxIterations = 100; // 無限ループ防止
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            $result = $this->receiveSingle();

            if ($result === null) {
                // ドキュメントなし、終了
                break;
            }

            $documents->push($result);

            Log::info('JX Document received', [
                'message_id' => $result->messageId,
                'document_type' => $result->documentType,
                'iteration' => $iteration,
            ]);
        }

        Log::info('JX Document receive completed', [
            'total_documents' => $documents->count(),
        ]);

        return $documents;
    }

    /**
     * 1件のドキュメントを受信
     *
     * @return JxReceivedDocument|null ドキュメントがない場合はnull
     */
    public function receiveSingle(): ?JxReceivedDocument
    {
        // 1. GetDocument リクエスト
        $getResult = $this->client->getDocument();

        if ($getResult->failed()) {
            Log::error('JX GetDocument failed', [
                'error' => $getResult->error,
                'message_id' => $getResult->messageId,
            ]);
            return null;
        }

        // 2. ドキュメントの有無を確認
        if (!$getResult->hasDocument()) {
            Log::info('JX GetDocument: No document available');
            return null;
        }

        // 3. データを抽出
        $receivedDocument = $this->extractDocument($getResult);

        // 4. ファイルを保存
        $savedPath = $this->saveDocument($receivedDocument);
        $receivedDocument->savedPath = $savedPath;

        // 5. ConfirmDocument を送信
        $confirmResult = $this->client->confirmDocument($getResult->messageId);

        if ($confirmResult->failed()) {
            Log::warning('JX ConfirmDocument failed', [
                'error' => $confirmResult->error,
                'original_message_id' => $getResult->messageId,
            ]);
            $receivedDocument->confirmed = false;
        } else {
            $receivedDocument->confirmed = true;
            Log::info('JX ConfirmDocument succeeded', [
                'original_message_id' => $getResult->messageId,
            ]);
        }

        // 6. 受信ログを記録
        $this->logReceive($receivedDocument);

        return $receivedDocument;
    }

    /**
     * 受信ログを記録
     */
    protected function logReceive(JxReceivedDocument $document): void
    {
        try {
            // ディスク情報をパスに含める（例: "s3:jx-received/..." または "local:jx-received/..."）
            $filePathWithDisk = "{$this->storageDisk}:{$document->savedPath}";

            WmsJxTransmissionLog::logReceive(
                jxSettingId: $this->setting->id,
                operationType: JxClient::DOCUMENT_TYPE_GET,
                messageId: $document->messageId,
                success: true,
                documentType: $document->documentType,
                formatType: $document->formatType,
                senderId: $document->senderId,
                receiverId: $document->receiverId,
                dataSize: $document->getDataSize(),
                filePath: $filePathWithDisk,
                httpCode: 200,
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log JX receive', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * レスポンスからドキュメントを抽出
     */
    protected function extractDocument(JxClientResult $result): JxReceivedDocument
    {
        $compressType = $result->getCompressType();
        $shouldDecompress = !empty($compressType) && strtolower($compressType) === 'gzip';

        $data = $result->getDecodedAndDecompressedData($shouldDecompress);

        return new JxReceivedDocument(
            messageId: $result->messageId,
            data: $data,
            documentType: $result->getDocumentType(),
            formatType: $result->getFormatType(),
            senderId: $result->getSenderId(),
            receiverId: $result->getReceiverId(),
            compressType: $compressType,
            receivedAt: Carbon::now(),
        );
    }

    /**
     * ドキュメントをストレージに保存
     */
    protected function saveDocument(JxReceivedDocument $document): string
    {
        $date = Carbon::today()->format('Y-m-d');
        $timestamp = Carbon::now()->format('YmdHis');
        $docType = $document->documentType ?? 'unknown';

        // ファイル名を生成
        $filename = "{$timestamp}_{$document->messageId}.dat";
        $path = "{$this->storageDirectory}/{$date}/{$docType}/{$filename}";

        // 保存
        Storage::disk($this->storageDisk)->put($path, $document->data ?? '');

        Log::info('JX Document saved', [
            'path' => $path,
            'disk' => $this->storageDisk,
            'size' => strlen($document->data ?? ''),
        ]);

        return $path;
    }
}
