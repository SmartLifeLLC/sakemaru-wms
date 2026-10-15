<?php

namespace Tests\Unit\Models;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\WmsStockTransferCandidate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 店間発注のケース発注: 発注数（ケース or バラ）から総バラ数を求める処理のテスト。
 */
class WmsStockTransferCandidatePieceQuantityTest extends TestCase
{
    #[DataProvider('pieceQuantityCases')]
    public function test_quantity_is_converted_to_pieces(
        int $quantity,
        QuantityType|string|null $quantityType,
        mixed $capacityCase,
        mixed $capacityCarton,
        int $expected,
    ): void {
        $this->assertSame(
            $expected,
            WmsStockTransferCandidate::toPieceQuantity($quantity, $quantityType, $capacityCase, $capacityCarton),
        );
    }

    public static function pieceQuantityCases(): array
    {
        return [
            'バラはそのまま' => [7, QuantityType::PIECE, 24, null, 7],
            'バラは入数が無くてもそのまま' => [7, 'PIECE', null, null, 7],
            'ケースは入数を掛ける' => [3, QuantityType::CASE, 12, null, 36],
            'ケース（文字列指定）' => [2, 'CASE', 24, null, 48],
            'ケース（入数が文字列）' => [2, 'CASE', '6', null, 12],
            'ケースで入数が未設定なら1として扱う' => [5, QuantityType::CASE, null, null, 5],
            'ケースで入数が0なら1として扱う' => [5, QuantityType::CASE, 0, null, 5],
            'ボールはボール入数を掛ける' => [4, QuantityType::CARTON, 24, 6, 24],
            '単位が未設定ならバラ扱い' => [9, null, 24, 6, 9],
            '不明な単位はバラ扱い' => [9, 'UNKNOWN', 24, 6, 9],
            '数量0は0' => [0, QuantityType::CASE, 24, null, 0],
        ];
    }

    #[DataProvider('caseOrderableCases')]
    public function test_case_order_is_allowed_only_when_capacity_is_set(mixed $capacityCase, bool $expected): void
    {
        $this->assertSame($expected, WmsStockTransferCandidate::canOrderByCase($capacityCase));
    }

    public static function caseOrderableCases(): array
    {
        return [
            '入数 12' => [12, true],
            '入数 1' => [1, true],
            '入数が文字列' => ['6', true],
            '入数 0 は不可（基幹側で総バラ数が 0 になる）' => [0, false],
            '入数が未設定は不可' => [null, false],
            '負の入数は不可' => [-1, false],
        ];
    }

    public function test_total_piece_quantity_uses_item_capacity_for_case_candidate(): void
    {
        $candidate = $this->candidate(3, QuantityType::CASE, 12);

        $this->assertSame(36, $candidate->totalPieceQuantity());
    }

    public function test_total_piece_quantity_equals_transfer_quantity_for_piece_candidate(): void
    {
        $candidate = $this->candidate(30, QuantityType::PIECE, 12);

        $this->assertSame(30, $candidate->totalPieceQuantity());
    }

    public function test_total_piece_quantity_does_not_fail_without_item(): void
    {
        $candidate = (new WmsStockTransferCandidate)->forceFill([
            'transfer_quantity' => 4,
            'quantity_type' => QuantityType::CASE,
        ]);
        $candidate->setRelation('item', null);

        $this->assertSame(4, $candidate->totalPieceQuantity());
    }

    public function test_piece_quantity_sql_matches_php_conversion_rules(): void
    {
        $sql = WmsStockTransferCandidate::pieceQuantitySql('tc', 'i');

        $this->assertSame(
            'CASE tc.quantity_type'
            ." WHEN 'CASE' THEN tc.transfer_quantity * GREATEST(COALESCE(i.capacity_case, 1), 1)"
            ." WHEN 'CARTON' THEN tc.transfer_quantity * GREATEST(COALESCE(i.capacity_carton, 1), 1)"
            .' ELSE tc.transfer_quantity END',
            $sql,
        );
    }

    private function candidate(int $quantity, QuantityType $type, int $capacityCase): WmsStockTransferCandidate
    {
        $candidate = (new WmsStockTransferCandidate)->forceFill([
            'transfer_quantity' => $quantity,
            'quantity_type' => $type,
        ]);
        $candidate->setRelation('item', (new Item)->forceFill(['capacity_case' => $capacityCase]));

        return $candidate;
    }
}
