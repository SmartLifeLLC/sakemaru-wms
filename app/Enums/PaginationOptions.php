<?php

namespace App\Enums;

/**
 * ページネーションオプションの統一管理
 *
 * システム全体で使用するページネーション設定を提供します。
 * デフォルト: 500件
 * 選択可能: 500, 1000, 1500, 2000
 */
class PaginationOptions
{
    /**
     * デフォルトの表示件数
     */
    public const DEFAULT = 100;

    /**
     * 選択可能なページオプション
     */
    public const OPTIONS = [100, 500, 1000, 1500, 2000];

    /**
     * ページオプションの配列を取得
     * Filament TableのpaginationPageOptions()に渡す形式
     */
    public static function all(): array
    {
        return self::OPTIONS;
    }

    /**
     * デフォルトのページサイズを取得
     */
    public static function default(): int
    {
        return self::DEFAULT;
    }

    /**
     * ラベル付きオプションを取得 (Select用)
     */
    public static function options(): array
    {
        return array_combine(
            self::OPTIONS,
            array_map(fn ($v) => number_format($v).'件', self::OPTIONS)
        );
    }
}
