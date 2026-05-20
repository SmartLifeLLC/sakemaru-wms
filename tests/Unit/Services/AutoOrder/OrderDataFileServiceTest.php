<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderDataFileService;
use Tests\TestCase;

class OrderDataFileServiceTest extends TestCase
{
    public function test_candidates_are_grouped_by_warehouse_contractor_and_expected_arrival_date(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 10, 100, 200, '2026-05-21'),
            $this->candidate(3, 10, 100, 200, '2026-05-20'),
            $this->candidate(4, 11, 100, 200, '2026-05-20'),
        ]), true);

        $this->assertCount(3, $groups);
        $this->assertSame([1, 3], $groups->get('10_100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([2], $groups->get('10_100_200_2026-05-21')->pluck('id')->all());
        $this->assertSame([4], $groups->get('11_100_200_2026-05-20')->pluck('id')->all());
    }

    public function test_candidates_are_grouped_by_contractor_and_expected_arrival_date_when_not_split_by_warehouse(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 11, 100, 200, '2026-05-20'),
            $this->candidate(3, 10, 100, 200, '2026-05-21'),
        ]), false);

        $this->assertCount(2, $groups);
        $this->assertSame([1, 2], $groups->get('100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([3], $groups->get('100_200_2026-05-21')->pluck('id')->all());
    }

    public function test_candidates_are_grouped_by_supplier_even_when_arrival_date_matches(): void
    {
        $service = new OrderDataFileService;
        $method = new \ReflectionMethod($service, 'groupCandidatesForDataFiles');
        $method->setAccessible(true);

        $groups = $method->invoke($service, collect([
            $this->candidate(1, 10, 100, 200, '2026-05-20'),
            $this->candidate(2, 10, 100, 201, '2026-05-20'),
        ]), true);

        $this->assertCount(2, $groups);
        $this->assertSame([1], $groups->get('10_100_200_2026-05-20')->pluck('id')->all());
        $this->assertSame([2], $groups->get('10_100_201_2026-05-20')->pluck('id')->all());
    }

    private function candidate(int $id, int $warehouseId, int $contractorId, int $supplierId, string $expectedArrivalDate): WmsOrderCandidate
    {
        $candidate = new WmsOrderCandidate([
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
            'supplier_id' => $supplierId,
            'expected_arrival_date' => $expectedArrivalDate,
        ]);
        $candidate->id = $id;

        return $candidate;
    }
}
