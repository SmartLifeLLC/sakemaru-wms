<?php

namespace App\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\EWMSClient;
use App\Models\WmsOrderJxSetting;

/**
 * 発注サービスファクトリー
 *
 * JX設定またはクライアント設定に基づいて適切なサービスインスタンスを提供する。
 */
class OrderServiceFactory
{
    /**
     * JX設定からファイル生成クラスを取得
     */
    public static function generatorForJxSetting(WmsOrderJxSetting $jxSetting): ?OrderFileGeneratorInterface
    {
        return $jxSetting->order_file_generator?->generator();
    }

    /**
     * 発注ファイル生成クラスを取得
     *
     * @deprecated JX設定経由で取得すること（generatorForJxSetting）
     */
    public static function generator(): OrderFileGeneratorInterface
    {
        return EWMSClient::current()->orderFileGenerator();
    }

    /**
     * 現在のクライアントを取得
     *
     * @deprecated
     */
    public static function currentClient(): EWMSClient
    {
        return EWMSClient::current();
    }
}
