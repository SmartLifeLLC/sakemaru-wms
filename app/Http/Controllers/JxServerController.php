<?php

namespace App\Http\Controllers;

use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * JX-FINETテスト用受信サーバー
 *
 * 開発・テスト環境でJX通信をシミュレートするためのコントローラ
 */
class JxServerController extends Controller
{
    /**
     * JXリクエストを処理
     */
    public function handle(Request $request): Response
    {
        // Basic認証からユーザーを取得
        $username = $request->getUser();
        $jxSetting = WmsOrderJxSetting::where('basic_user_id', $username)->first();

        $xmlData = $request->getContent();

        // リクエストを保存
        $this->saveRequest($xmlData);

        try {
            $doc = new DOMDocument();
            $doc->loadXML($xmlData);

            $timestamp = getJxTimestamp('UTC');
            $data = [
                'timestamp' => $timestamp,
                'from' => $doc->getElementsByTagName('To')->item(0)?->nodeValue ?? '',
                'to' => $doc->getElementsByTagName('From')->item(0)?->nodeValue ?? '',
            ];

            // リクエストタイプに応じてレスポンスを生成
            if ($doc->getElementsByTagName('GetDocument')->count() > 0) {
                return $this->handleGetDocument($doc, $data, $jxSetting);
            }

            if ($doc->getElementsByTagName('PutDocument')->count() > 0) {
                return $this->handlePutDocument($doc, $data);
            }

            if ($doc->getElementsByTagName('ConfirmDocument')->count() > 0) {
                return $this->handleConfirmDocument($doc, $data);
            }

            Log::warning('JxServer: Unknown document type received');
            return response('Method not allowed', 405);
        } catch (\Exception $e) {
            Log::error('JxServer: Error processing request', [
                'message' => $e->getMessage(),
            ]);
            return response('Internal Server Error', 500);
        }
    }

    /**
     * GetDocumentリクエストを処理
     */
    protected function handleGetDocument(DOMDocument $doc, array $data, ?WmsOrderJxSetting $jxSetting): Response
    {
        $receiverId = $doc->getElementsByTagName('receiverId')->item(0)?->nodeValue ?? '';
        $messageId = createJxMessageId('get', $data['from']);
        $documentMessageId = createJxMessageId('get_data', $data['from']);

        $responseData = array_merge($data, [
            'receiver_id' => $receiverId,
            'sender_id' => $jxSetting?->server_id ?? 'TEST_SERVER',
            'result' => 'true',
            'message_id' => $messageId,
            'document_message_id' => $documentMessageId,
        ]);

        Log::info('JxServer: GetDocument received', ['receiver_id' => $receiverId]);

        $responseXml = View::make('jx.get-document-response', $responseData)->render();

        return response($responseXml)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * PutDocumentリクエストを処理
     */
    protected function handlePutDocument(DOMDocument $doc, array $data): Response
    {
        $documentType = $doc->getElementsByTagName('documentType')->item(0)?->nodeValue ?? '';
        $documentData = $doc->getElementsByTagName('data')->item(0)?->nodeValue ?? '';
        $receivedMessageId = $doc->getElementsByTagName('messageId')->item(0)?->nodeValue ?? '';
        $messageId = createJxMessageId('put', $data['from']);

        Log::info('JxServer: PutDocument received', [
            'document_type' => $documentType,
            'message_id' => $receivedMessageId,
        ]);

        // 受信データを保存
        $this->savePutDocumentData($receivedMessageId, $documentType, $documentData);

        $responseData = array_merge($data, [
            'result' => 'true',
            'message_id' => $messageId,
        ]);

        $responseXml = View::make('jx.put-document-response', $responseData)->render();

        return response($responseXml)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * ConfirmDocumentリクエストを処理
     */
    protected function handleConfirmDocument(DOMDocument $doc, array $data): Response
    {
        $confirmedMessageId = $doc->getElementsByTagName('messageId')->item(0)?->nodeValue ?? '';
        $messageId = createJxMessageId('confirm', $data['from']);

        Log::info('JxServer: ConfirmDocument received', [
            'confirmed_message_id' => $confirmedMessageId,
        ]);

        $responseData = array_merge($data, [
            'result' => 'true',
            'message_id' => $messageId,
        ]);

        $responseXml = View::make('jx.confirm-document-response', $responseData)->render();

        return response($responseXml)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * リクエストを保存
     */
    protected function saveRequest(string $xmlData): void
    {
        $date = Carbon::now()->format('Y-m-d');
        $timestamp = Carbon::now()->format('YmdHis');
        $filename = "jx-server/received/{$date}/{$timestamp}_request.xml";

        Storage::disk('local')->put($filename, $xmlData);
    }

    /**
     * PutDocumentのデータを保存
     */
    protected function savePutDocumentData(string $messageId, string $documentType, string $data): void
    {
        $date = Carbon::now()->format('Y-m-d');
        $decodedData = base64_decode($data);
        $filename = "jx-server/documents/{$date}/{$documentType}_{$messageId}.txt";

        Storage::disk('local')->put($filename, $decodedData);
    }
}
