<?php

namespace App\Services\PickingList;

/** Display-only normalization; keep location IDs, grouping and picking order unchanged. */
final class PickingListLocationLabel
{
    public static function format(?string $code): string
    {
        $code = trim((string) $code);
        $normalized = mb_strtoupper((string) preg_replace('/[\s\-_]+/u', '', $code));

        return in_array($normalized, ['', 'Z00', '未設定', 'フリーロケ', 'ノーロケ'], true)
            ? 'ノーロケ'
            : $code;
    }
}
