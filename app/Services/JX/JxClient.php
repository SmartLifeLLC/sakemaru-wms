<?php

namespace App\Services\JX;

use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use App\Support\JxRequestResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * JX-FINET送信クライアント
 */
class JxClient
{
    public const DOCUMENT_TYPE_GET = 'GetDocument';

    public const DOCUMENT_TYPE_PUT = 'PutDocument';

    public const DOCUMENT_TYPE_CONFIRM = 'ConfirmDocument';

    protected WmsOrderJxSetting $setting;

    public function __construct(WmsOrderJxSetting $setting)
    {
        $this->setting = $setting;
    }

    /**
     * ドキュメントを送信 (PutDocument)
     *
     * @param  string  $data  Base64エンコード済みデータ
     * @param  string  $documentType  ドキュメントタイプ (例: '01' = 発注)
     * @param  string  $formatType  フォーマットタイプ (例: 'SecondGenEDI')
     * @param  string|null  $compressType  圧縮タイプ
     */
    public function putDocument(
        string $data,
        string $documentType,
        string $formatType = 'SecondGenEDI',
        ?string $compressType = null
    ): JxClientResult {
        $messageId = createJxMessageId('put', $this->setting->jx_from);

        // 送信データ本体を保存（Base64デコードして保存）
        $decodedData = base64_decode($data);
        $dataFilePath = $this->saveTransmittedData($documentType, $messageId, $decodedData);

        $viewData = [
            'from' => $this->setting->jx_from,
            'to' => $this->setting->jx_to,
            'message_id' => $messageId,
            'sender_id' => $this->setting->jx_client_id,
            'receiver_id' => $this->setting->server_id,
            'document_type' => $documentType,
            'compress_type' => $compressType ?? '',
            'format_type' => $formatType,
            'data' => $data,
            'timestamp' => getJxTimestamp('UTC'),
            '_data_file_path' => $dataFilePath, // ログ用
        ];

        return $this->sendRequest(self::DOCUMENT_TYPE_PUT, $viewData);
    }

    /**
     * ヘッダー・フッター付きでドキュメントを送信 (PutDocument)
     *
     * ヘッダー・フッターがないデータに自動的に追加してから送信
     *
     * @param  string  $rawData  生データ（Base64エンコード前）
     * @param  string  $documentType  ドキュメントタイプ (例: '01' = 発注)
     * @param  string  $formatType  フォーマットタイプ (例: 'SecondGenEDI')
     * @param  string|null  $compressType  圧縮タイプ
     */
    public function putDocumentWithWrapper(
        string $rawData,
        string $documentType,
        string $formatType = 'SecondGenEDI',
        ?string $compressType = null
    ): JxClientResult {
        // ヘッダー・フッターがない場合は追加
        if (! JxDataWrapper::hasHeader($rawData)) {
            $wrapper = new JxDataWrapper($this->setting);
            $rawData = $wrapper->wrap($rawData);
        }

        // Base64エンコードして送信
        $encodedData = base64_encode($rawData);

        return $this->putDocument($encodedData, $documentType, $formatType, $compressType);
    }

    /**
     * ドキュメントを取得 (GetDocument)
     */
    public function getDocument(): JxClientResult
    {
        $messageId = createJxMessageId('get', $this->setting->jx_from);
        $toHost = parse_url($this->setting->endpoint_url, PHP_URL_HOST);

        $viewData = [
            'from' => $this->setting->jx_from,
            'to' => $toHost,
            'message_id' => $messageId,
            'receiver_id' => $this->setting->jx_client_id,
            'timestamp' => getJxTimestamp('UTC'),
        ];

        return $this->sendRequest(self::DOCUMENT_TYPE_GET, $viewData);
    }

    /**
     * ドキュメント受信確認 (ConfirmDocument)
     *
     * @param  string  $receivedMessageId  受信したメッセージID
     */
    public function confirmDocument(string $receivedMessageId): JxClientResult
    {
        $messageId = createJxMessageId('confirm', $this->setting->jx_from);
        $toHost = parse_url($this->setting->endpoint_url, PHP_URL_HOST);

        $viewData = [
            'from' => $this->setting->jx_from,
            'to' => $toHost,
            'message_id' => $messageId,
            'received_message_id' => $receivedMessageId,
            'receiver_id' => $this->setting->jx_client_id,
            'sender_id' => $this->setting->server_id,
            'timestamp' => getJxTimestamp('UTC'),
        ];

        return $this->sendRequest(self::DOCUMENT_TYPE_CONFIRM, $viewData);
    }

    /**
     * JXリクエストを送信
     */
    protected function sendRequest(string $documentType, array $viewData): JxClientResult
    {
        $viewFile = $this->getViewFile($documentType);
        $xmlData = View::make($viewFile, $viewData)->render();
        $soapAction = 'http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server/'.$documentType;
        $dataSize = isset($viewData['data']) ? strlen($viewData['data']) : null;

        // リクエストを保存
        $this->saveRequest($documentType, $viewData['message_id'], $xmlData);

        try {
            $response = $this->doRequest($xmlData, $soapAction);

            if ($response->failed()) {
                Log::error('JX request failed', [
                    'http_code' => $response->httpCode,
                    'message_id' => $viewData['message_id'],
                    'response_body' => $response->bodyString,
                ]);

                // エラー時もレスポンスを保存（デバッグ用）
                $this->saveResponse($documentType.'_error', $viewData['message_id'], $response->bodyString);

                // 失敗ログを記録
                $this->logTransmission(
                    $documentType,
                    $viewData,
                    false,
                    $response->httpCode,
                    "HTTP Code: {$response->httpCode}",
                    $dataSize
                );

                return JxClientResult::failure(
                    "HTTP Code: {$response->httpCode}",
                    $viewData['message_id'],
                    $response
                );
            }

            // レスポンスを保存
            $this->saveResponse($documentType, $viewData['message_id'], $response->bodyString);

            // ログ用のファイルパス（PutDocumentの場合はデータ本体、それ以外はレスポンス）
            $logFilePath = $viewData['_data_file_path'] ?? null;

            // 成功ログを記録
            $this->logTransmission(
                $documentType,
                $viewData,
                true,
                $response->httpCode,
                null,
                $dataSize,
                $logFilePath
            );

            return JxClientResult::success(
                $viewData['message_id'],
                $response
            );
        } catch (\Exception $e) {
            Log::error('JX request exception', [
                'message' => $e->getMessage(),
                'message_id' => $viewData['message_id'],
            ]);

            // 例外ログを記録
            $this->logTransmission(
                $documentType,
                $viewData,
                false,
                null,
                $e->getMessage(),
                $dataSize
            );

            return JxClientResult::failure(
                $e->getMessage(),
                $viewData['message_id']
            );
        }
    }

    /**
     * 送信ログを記録
     */
    protected function logTransmission(
        string $documentType,
        array $viewData,
        bool $success,
        ?int $httpCode,
        ?string $errorMessage,
        ?int $dataSize = null,
        ?string $filePath = null
    ): void {
        try {
            WmsJxTransmissionLog::logSend(
                jxSettingId: $this->setting->id,
                operationType: $documentType,
                messageId: $viewData['message_id'],
                success: $success,
                documentType: $viewData['document_type'] ?? null,
                formatType: $viewData['format_type'] ?? null,
                dataSize: $dataSize,
                filePath: $filePath,
                httpCode: $httpCode,
                errorMessage: $errorMessage,
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log JX transmission', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * HTTP リクエストを実行
     */
    protected function doRequest(string $xmlString, string $soapAction): JxRequestResponse
    {
        $headers = [
            'Content-type: text/xml;charset="UTF-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: '.$soapAction,
            'Content-length: '.strlen($xmlString),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'SOAP-RPC Client; Smart-WMS JX-Client 1.0;');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_URL, $this->setting->endpoint_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

        // SSL証明書
        if ($this->setting->ssl_certification_file) {
            $certPath = $this->setting->ssl_certification_file;
            if (! Storage::disk('local')->exists($certPath)) {
                Storage::disk('local')->put($certPath, Storage::disk('s3')->get($certPath));
            }
            curl_setopt($ch, CURLOPT_CAINFO, Storage::disk('local')->path($certPath));
        }

        // Basic認証
        if ($this->setting->is_basic_auth) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->setting->basic_user_id.':'.$this->setting->basic_user_pw);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);

        return new JxRequestResponse($ch, $response);
    }

    /**
     * ビューファイル名を取得
     */
    protected function getViewFile(string $documentType): string
    {
        return match ($documentType) {
            self::DOCUMENT_TYPE_GET => 'jx.get-document',
            self::DOCUMENT_TYPE_PUT => 'jx.put-document',
            self::DOCUMENT_TYPE_CONFIRM => 'jx.confirm-document',
            default => throw new \InvalidArgumentException("Invalid document type: {$documentType}"),
        };
    }

    /**
     * レスポンスを保存
     */
    protected function saveResponse(string $documentType, string $messageId, string $body): string
    {
        $date = Carbon::today()->format('Y-m-d');
        $docType = strtolower($documentType);
        $timestamp = Carbon::now()->format('YmdHis');
        $savePath = "jx-client/responses/{$date}/{$docType}/{$timestamp}/{$messageId}.xml";

        Storage::disk('local')->put($savePath, $body);

        return $savePath;
    }

    /**
     * リクエストを保存
     */
    protected function saveRequest(string $documentType, string $messageId, string $body): string
    {
        $date = Carbon::today()->format('Y-m-d');
        $docType = strtolower($documentType);
        $timestamp = Carbon::now()->format('YmdHis');
        $savePath = "jx-client/requests/{$date}/{$docType}/{$timestamp}/{$messageId}.xml";

        Storage::disk('local')->put($savePath, $body);

        return $savePath;
    }

    /**
     * 送信データ本体を保存
     */
    protected function saveTransmittedData(string $documentType, string $messageId, string $data): string
    {
        $date = Carbon::today()->format('Y-m-d');
        $timestamp = Carbon::now()->format('YmdHis');
        $savePath = "jx-client/data/{$date}/{$documentType}/{$timestamp}_{$messageId}.dat";

        Storage::disk('local')->put($savePath, $data);

        return "local:{$savePath}";
    }
}
