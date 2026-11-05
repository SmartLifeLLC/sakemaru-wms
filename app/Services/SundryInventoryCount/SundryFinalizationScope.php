<?php

namespace App\Services\SundryInventoryCount;

use Illuminate\Validation\ValidationException;

final class SundryFinalizationScope
{
    public const ALL = 'all';

    public const ENTERED_MANAGED = 'entered_managed';

    public const SUBMITTED_SEPTEMBER = 'entered_managed_submitted_september';

    public static function options(): array
    {
        return [
            self::ALL => '管理品・非管理品の全対象',
            self::ENTERED_MANAGED => '入力済み管理品のみ（非管理品は適用しない）',
            self::SUBMITTED_SEPTEMBER => '入力済み管理品＋顧客提出の非管理品差異（2026年9月）',
        ];
    }

    public static function validate(string $scope): void
    {
        if (! array_key_exists($scope, self::options())) {
            throw ValidationException::withMessages(['finalization' => '棚卸しの適用対象を選択し直してください。']);
        }
    }

    public static function label(string $scope): string
    {
        self::validate($scope);

        return self::options()[$scope];
    }
}
