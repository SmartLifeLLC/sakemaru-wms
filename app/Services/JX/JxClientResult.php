<?php

namespace App\Services\JX;

use App\Support\JxRequestResponse;

/**
 * JxClientの結果を表すクラス
 */
class JxClientResult
{
    public readonly bool $success;
    public readonly ?string $error;
    public readonly string $messageId;
    public readonly ?JxRequestResponse $response;

    private function __construct(
        bool $success,
        string $messageId,
        ?string $error = null,
        ?JxRequestResponse $response = null
    ) {
        $this->success = $success;
        $this->messageId = $messageId;
        $this->error = $error;
        $this->response = $response;
    }

    /**
     * 成功結果を作成
     */
    public static function success(string $messageId, ?JxRequestResponse $response = null): self
    {
        return new self(true, $messageId, null, $response);
    }

    /**
     * 失敗結果を作成
     */
    public static function failure(string $error, string $messageId, ?JxRequestResponse $response = null): self
    {
        return new self(false, $messageId, $error, $response);
    }

    /**
     * 成功したかどうか
     */
    public function succeeded(): bool
    {
        return $this->success;
    }

    /**
     * 失敗したかどうか
     */
    public function failed(): bool
    {
        return !$this->success;
    }

    /**
     * XMLタグから値を取得
     */
    public function getValueByTag(string $tagName): ?string
    {
        return $this->response?->getValueByTag($tagName);
    }

    /**
     * PutDocumentの結果を取得
     */
    public function getPutDocumentResult(): ?bool
    {
        $result = $this->getValueByTag('PutDocumentResult');
        return $result !== null ? $result === 'true' : null;
    }

    /**
     * GetDocumentの結果を取得
     */
    public function getGetDocumentResult(): ?bool
    {
        $result = $this->getValueByTag('GetDocumentResult');
        return $result !== null ? $result === 'true' : null;
    }

    /**
     * 受信データを取得
     */
    public function getData(): ?string
    {
        return $this->getValueByTag('data');
    }

    /**
     * 受信データをデコードして取得
     */
    public function getDecodedData(): ?string
    {
        $data = $this->getData();
        return $data !== null ? base64_decode($data) : null;
    }

    /**
     * 受信データをデコード＆解凍して取得
     *
     * @param bool $decompress GZIP解凍するかどうか
     */
    public function getDecodedAndDecompressedData(bool $decompress = false): ?string
    {
        $data = $this->getDecodedData();

        if ($data === null) {
            return null;
        }

        if ($decompress && $this->isGzipped($data)) {
            return gzdecode($data);
        }

        return $data;
    }

    /**
     * データがGZIP圧縮されているか判定
     */
    protected function isGzipped(string $data): bool
    {
        // GZIPマジックナンバー: 0x1f 0x8b
        return strlen($data) >= 2 && ord($data[0]) === 0x1f && ord($data[1]) === 0x8b;
    }

    /**
     * 圧縮タイプを取得
     */
    public function getCompressType(): ?string
    {
        return $this->getValueByTag('compressType');
    }

    /**
     * ドキュメントタイプを取得
     */
    public function getDocumentType(): ?string
    {
        return $this->getValueByTag('documentType');
    }

    /**
     * フォーマットタイプを取得
     */
    public function getFormatType(): ?string
    {
        return $this->getValueByTag('formatType');
    }

    /**
     * 送信者IDを取得
     */
    public function getSenderId(): ?string
    {
        return $this->getValueByTag('senderId');
    }

    /**
     * 受信者IDを取得
     */
    public function getReceiverId(): ?string
    {
        return $this->getValueByTag('receiverId');
    }

    /**
     * ドキュメントがあるかどうか
     */
    public function hasDocument(): bool
    {
        return $this->getData() !== null && $this->getData() !== '';
    }

    /**
     * 受信ドキュメントのメッセージIDを取得
     *
     * GetDocumentResponseの<messageId>タグから取得
     * （リクエストのmessageIdとは異なる）
     */
    public function getReceivedMessageId(): ?string
    {
        return $this->getValueByTag('messageId');
    }
}
