<?php

namespace App\Support;

use CurlHandle;
use DOMDocument;

/**
 * JX通信のリクエスト・レスポンスを扱うクラス
 */
class JxRequestResponse
{
    public readonly CurlHandle $ch;
    public readonly string|bool $originalResponse;
    public readonly bool $isSuccess;
    public readonly string $headerString;
    public readonly string $bodyString;
    public readonly int $httpCode;
    public readonly array $responseInfo;
    public readonly ?DOMDocument $document;

    public function __construct(CurlHandle $ch, string|bool $response)
    {
        $this->ch = $ch;
        $this->originalResponse = $response;
        $info = curl_getinfo($ch);
        $this->responseInfo = $info;
        $this->httpCode = $info['http_code'];

        if ($response === false) {
            $this->headerString = '';
            $this->bodyString = '';
            $this->isSuccess = false;
            $this->document = null;
        } else {
            // ヘッダ部分を取得
            $this->headerString = substr($response, 0, $info['header_size']);
            // ボディ部分を取得
            $this->bodyString = substr($response, $info['header_size']);

            if ($this->httpCode < 300) {
                $this->isSuccess = true;
                $this->document = new DOMDocument();
                $this->document->loadXML($this->bodyString);
            } else {
                $this->isSuccess = false;
                $this->document = null;
            }
        }
    }

    /**
     * XMLタグから値を取得
     *
     * @param string $tagName タグ名
     * @return string|null
     * @throws \Exception 複数の値が見つかった場合
     */
    public function getValueByTag(string $tagName): ?string
    {
        if ($this->document === null) {
            return null;
        }

        $items = $this->document->getElementsByTagName($tagName);

        if ($items->count() === 1) {
            return $items->item(0)->nodeValue;
        } elseif ($items->count() > 1) {
            throw new \Exception("Multiple values found for tag: {$tagName}");
        }

        return null;
    }

    /**
     * リクエストが成功したかどうか
     */
    public function succeeded(): bool
    {
        return $this->isSuccess;
    }

    /**
     * リクエストが失敗したかどうか
     */
    public function failed(): bool
    {
        return !$this->isSuccess;
    }
}
