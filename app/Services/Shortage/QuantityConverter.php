<?php

namespace App\Services\Shortage;

/**
 * 数量変換ユーティリティ
 * CASE/CARTON/PIECE ←→ PIECE（最小単位）の相互変換
 */
class QuantityConverter
{
    /**
     * CASE/PIECE/CARTON → PIECE変換
     *
     * @param  int  $qty  数量
     * @param  string  $qtyType  単位タイプ (CASE, PIECE, CARTON)
     * @param  int  $caseSize  ケース入数
     * @return int PIECE換算数量
     *
     * @throws \Exception
     */
    public static function convertToEach(int $qty, string $qtyType, int $caseSize): int
    {
        return match ($qtyType) {
            'CASE' => $qty * $caseSize,
            'CARTON' => $qty * $caseSize, // CARTONも現状はCASEと同じ入数
            'PIECE' => $qty,
            default => throw new \Exception("Invalid qtyType: {$qtyType}"),
        };
    }

    /**
     * PIECE → 表示用 CASE/PIECE 変換
     *
     * @param  int  $each  PIECE数量
     * @param  int  $caseSize  ケース入数
     * @return array{case: int, piece: int}
     */
    public static function convertFromEach(int $each, int $caseSize): array
    {
        if ($caseSize <= 1) {
            return [
                'case' => 0,
                'piece' => $each,
            ];
        }

        return [
            'case' => intdiv($each, $caseSize),
            'piece' => $each % $caseSize,
        ];
    }

    /**
     * CASE表示用の文字列を生成
     *
     * @param  int  $each  PIECE数量
     * @param  int  $caseSize  ケース入数
     * @return string 例: "2ケース 5個"
     */
    public static function formatCaseDisplay(int $each, int $caseSize): string
    {
        $converted = self::convertFromEach($each, $caseSize);

        $parts = [];
        if ($converted['case'] > 0) {
            $parts[] = "{$converted['case']}ケース";
        }
        if ($converted['piece'] > 0) {
            $parts[] = "{$converted['piece']}個";
        }

        return implode(' ', $parts) ?: '0個';
    }
}
