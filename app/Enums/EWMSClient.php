<?php

namespace App\Enums;

use App\Contracts\OrderFileGeneratorInterface;
use App\Services\AutoOrder\Generators\DefaultOrderFileGenerator;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;

/**
 * WMSクライアント種別
 *
 * 導入先（クライアント）ごとに異なる実装クラスを提供する。
 * 顧客別に同じ対応はほぼないため、毎回カスタム実装が必要。
 * DBでの設定管理は意味がないため、Enumで直接管理する。
 *
 * @deprecated EOrderFileGenerator を使用し、JX設定経由でgeneratorを取得すること
 */
enum EWMSClient: string
{
    case HANA = 'hana';
    case DEFAULT = 'default';

    /**
     * 発注ファイル生成クラスを取得
     */
    public function orderFileGeneratorClass(): string
    {
        return match ($this) {
            self::HANA => HanaOrderJXFileGenerator::class,
            self::DEFAULT => DefaultOrderFileGenerator::class,
        };
    }

    /**
     * 発注ファイル生成インスタンスを取得
     */
    public function orderFileGenerator(): OrderFileGeneratorInterface
    {
        return app($this->orderFileGeneratorClass());
    }

    /**
     * 現在のクライアントを取得
     */
    public static function current(): self
    {
        $value = config('wms.client');

        return self::tryFrom($value) ?? self::DEFAULT;
    }

    /**
     * ラベルを取得
     */
    public function label(): string
    {
        return match ($this) {
            self::HANA => 'リカーワールド ハナ',
            self::DEFAULT => 'デフォルト',
        };
    }
}
