<?php

namespace Tests\Unit\Models;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderSlipNumberAssignment;
use RuntimeException;
use Tests\TestCase;

class WmsOrderIncomingScheduleTest extends TestCase
{
    public function test_format_slip_number_uses_eleven_digit_yymmdd_sequence(): void
    {
        $slipNumber = WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 1000);

        $this->assertSame('26050601000', $slipNumber);
        $this->assertSame(11, strlen($slipNumber));
    }

    public function test_format_slip_number_rejects_sequences_that_do_not_fit_eleven_digits(): void
    {
        $this->expectException(RuntimeException::class);

        WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 100000);
    }

    public function test_purchase_transmission_scope_excludes_transfer_and_internal_contractors(): void
    {
        $query = WmsOrderIncomingSchedule::query()->forPurchaseTransmission();
        $sql = $query->toSql();

        $this->assertSame([
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            TransmissionType::INTERNAL->value,
        ], $query->getBindings());
        $this->assertStringContainsString('transfer_candidate_id', $sql);
        $this->assertStringContainsString('source_warehouse_id', $sql);
        $this->assertStringContainsString('stock_transfer_id', $sql);
        $this->assertStringContainsString('wms_contractor_settings', $sql);
        $this->assertStringContainsString('transmission_type', $sql);
    }

    public function test_ready_for_purchase_transmission_scope_limits_to_unqueued_confirmed_selected_warehouse(): void
    {
        $warehouseId = 21;
        $query = WmsOrderIncomingSchedule::query()->readyForPurchaseTransmission($warehouseId);
        $sql = $query->toSql();

        $this->assertSame([
            IncomingScheduleStatus::CONFIRMED->value,
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            TransmissionType::INTERNAL->value,
            $warehouseId,
        ], $query->getBindings());
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('purchase_queue_id', $sql);
        $this->assertStringContainsString('warehouse_id', $sql);
        $this->assertStringContainsString('wms_contractor_settings', $sql);
    }

    public function test_ready_for_incoming_transmission_scope_keeps_transfer_and_internal_sources_processable(): void
    {
        $warehouseId = 21;
        $query = WmsOrderIncomingSchedule::query()->readyForIncomingTransmission($warehouseId);
        $sql = $query->toSql();

        $this->assertSame([
            IncomingScheduleStatus::CONFIRMED->value,
            $warehouseId,
        ], $query->getBindings());
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('purchase_queue_id', $sql);
        $this->assertStringContainsString('warehouse_id', $sql);
        $this->assertStringNotContainsString('order_source', $sql);
        $this->assertStringNotContainsString('wms_contractor_settings', $sql);
    }

    public function test_is_eos_sent_returns_false_without_order_candidate(): void
    {
        $schedule = new WmsOrderIncomingSchedule;

        $this->assertFalse($schedule->isEosSent());
    }

    public function test_is_eos_sent_returns_true_when_order_candidate_has_jx_document(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'order_candidate_id' => 10,
        ]);
        $schedule->setRelation('orderCandidate', new WmsOrderCandidate([
            'wms_order_jx_document_id' => 20,
        ]));

        $this->assertTrue($schedule->isEosSent());
    }

    public function test_is_eos_sent_returns_true_when_slip_assignment_contains_candidate(): void
    {
        $candidateId = random_int(900000, 999999);

        do {
            $slipNumber = '990101'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (WmsOrderSlipNumberAssignment::query()->where('slip_number', $slipNumber)->exists());

        $assignment = WmsOrderSlipNumberAssignment::query()->create([
            'wms_order_jx_document_id' => null,
            'document_type' => 'EOS_ORDER',
            'slip_number' => $slipNumber,
            'store_code' => '01',
            'year_code' => 9,
            'sequence_no' => (int) substr($slipNumber, 6),
            'status' => WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
            'order_candidate_ids' => [$candidateId],
        ]);

        try {
            $schedule = new WmsOrderIncomingSchedule([
                'order_candidate_id' => $candidateId,
            ]);

            $this->assertTrue($schedule->isEosSent());
        } finally {
            $assignment->delete();
        }
    }
}
