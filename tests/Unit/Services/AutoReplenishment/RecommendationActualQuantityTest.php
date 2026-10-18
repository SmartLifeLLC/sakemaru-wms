<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use Tests\TestCase;

class RecommendationActualQuantityTest extends TestCase
{
    public function test_case_carton_and_piece_use_the_actual_document_capacity(): void
    {
        $method = new \ReflectionMethod(SettingsRecommendationHistory::class, 'actualPieces');
        $reader = new SettingsRecommendationHistory;
        foreach ([['CASE', 11, 264], ['CARTON', 11, 66], ['PIECE', 11, 11]] as [$type, $qty, $total]) {
            self::assertSame($total, $method->invoke($reader, (object) ['quantity_type' => $type, 'quantity' => $qty,
                'capacity_case' => 24, 'capacity_carton' => 6, 'total_piece_quantity' => $total]));
        }
    }

    public function test_mismatched_total_is_rejected_instead_of_treating_cases_as_pieces(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('総バラ数が一致しません');
        (new \ReflectionMethod(SettingsRecommendationHistory::class, 'actualPieces'))->invoke(new SettingsRecommendationHistory,
            (object) ['quantity_type' => 'CASE', 'quantity' => 11, 'capacity_case' => 24, 'total_piece_quantity' => 11]);
    }
}
