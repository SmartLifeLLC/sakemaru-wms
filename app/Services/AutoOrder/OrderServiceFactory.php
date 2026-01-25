<?php

namespace App\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\EWMSClient;

/**
 * 発注サービスファクトリー
 *
 * 現在のクライアント設定に基づいて適切なサービスインスタンスを提供する。
 */
class OrderServiceFactory
{
    /**
     * 発注ファイル生成クラスを取得
     */
    public static function generator(): OrderFileGeneratorInterface
    {
        return EWMSClient::current()->orderFileGenerator();
    }

    /**
     * 現在のクライアントを取得
     */
    public static function currentClient(): EWMSClient
    {
        return EWMSClient::current();
    }
}
