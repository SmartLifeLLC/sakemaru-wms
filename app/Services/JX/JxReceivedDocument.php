<?php

namespace App\Services\JX;

use Carbon\Carbon;

/**
 * JX受信ドキュメント
 */
class JxReceivedDocument
{
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $data,
        public readonly ?string $documentType,
        public readonly ?string $formatType,
        public readonly ?string $senderId,
        public readonly ?string $receiverId,
        public readonly ?string $compressType,
        public readonly Carbon $receivedAt,
        public ?string $savedPath = null,
        public bool $confirmed = false,
    ) {}

    /**
     * データサイズ（バイト）
     */
    public function getDataSize(): int
    {
        return strlen($this->data ?? '');
    }

    /**
     * データをShift-JISからUTF-8に変換
     */
    public function getDataAsUtf8(): ?string
    {
        if ($this->data === null) {
            return null;
        }

        // Shift-JISからUTF-8に変換
        $utf8 = mb_convert_encoding($this->data, 'UTF-8', 'SJIS-win');

        return $utf8 !== false ? $utf8 : $this->data;
    }

    /**
     * 配列に変換
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'document_type' => $this->documentType,
            'format_type' => $this->formatType,
            'sender_id' => $this->senderId,
            'receiver_id' => $this->receiverId,
            'compress_type' => $this->compressType,
            'data_size' => $this->getDataSize(),
            'saved_path' => $this->savedPath,
            'confirmed' => $this->confirmed,
            'received_at' => $this->receivedAt->toIso8601String(),
        ];
    }
}
