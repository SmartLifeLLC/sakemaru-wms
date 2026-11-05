<?php

namespace Tests\Feature\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use App\Models\User;
use App\Models\WmsContractorWarehouseSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\PurchaseOrderPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class FaxOrderDataFileIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.s3' => [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/wms-fax-grouping-'.Str::uuid(),
        ]]);
        Storage::forgetDisk('s3');
        $this->actingAs((new User)->forceFill(['id' => 123, 'name' => '発注担当者']));

        // PDFの分割・明細保持の検証は納入先指定CDマスタに依存させない。
        Mockery::mock('alias:'.WmsContractorWarehouseSetting::class)
            ->shouldReceive('getDesignatedCode')->andReturn(null);
    }

    protected function tearDown(): void
    {
        Storage::disk('s3')->deleteDirectory('');

        parent::tearDown();
    }

    public function test_fax_pdf_combines_suppliers_and_keeps_both_lines_when_regenerated(): void
    {
        $candidates = $this->candidates();
        $result = app(OrderDataFileService::class)->generateFaxPdfFilesForCandidates(
            $candidates->modelKeys(),
            OrderDataFileChannel::FAX,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_files']);
        $file = $result['files'][0];
        $this->assertNull($file['fax_error']);
        $this->assertNull($file['supplier_id']);
        $this->assertNull($file['supplier_name']);
        $this->assertSame(2, $file['order_count']);
        $this->assertEqualsCanonicalizing($candidates->modelKeys(), $file['candidate_ids']);
        $this->assertSame(5 * max(1, (int) $candidates->first()->item->capacity_case), $file['total_piece_quantity']);

        $dataFile = WmsOrderDataFile::findOrFail($file['id']);
        $this->assertEqualsCanonicalizing($candidates->modelKeys(), $dataFile->candidate_ids);
        $this->assertSame('', $dataFile->file_path);
        $pdf = Storage::disk('s3')->get($dataFile->fax_file_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('~/Type /Pages\s+/Kids\s+\[[^]]*\]\s+/Count 1\b~', $pdf);

        $regenerated = app(PurchaseOrderPdfService::class)->generateFromDataFile($dataFile, '通信欄テスト');
        $this->assertStringStartsWith('%PDF', $regenerated);
        $this->assertMatchesRegularExpression('~/Type /Pages\s+/Kids\s+\[[^]]*\]\s+/Count 1\b~', $regenerated);
        $this->assertEqualsCanonicalizing(
            $candidates->pluck('supplier_id')->all(),
            WmsOrderCandidate::whereIn('id', $candidates->modelKeys())->pluck('supplier_id')->all(),
        );
        $this->assertSame(2, WmsOrderCandidate::whereIn('id', $candidates->modelKeys())->count());
    }

    public function test_eos_control_pdfs_keep_existing_supplier_split(): void
    {
        $candidates = $this->candidates(OrderChannel::EOS);
        $result = app(OrderDataFileService::class)->generateFaxPdfFilesForCandidates(
            $candidates->modelKeys(),
            OrderDataFileChannel::EOS,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total_files']);
        foreach ($result['files'] as $file) {
            $this->assertNull($file['fax_error']);
            $this->assertSame(1, $file['order_count']);
            $this->assertTrue(WmsOrderDataFile::findOrFail($file['id'])->isEosControlPdf());
        }
    }

    public function test_csv_generation_keeps_existing_supplier_split(): void
    {
        $candidates = $this->candidates();
        $result = app(OrderDataFileService::class)->generateCsvFilesForCandidates($candidates->modelKeys());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total_files']);
        foreach ($result['files'] as $file) {
            $this->assertNull($file['fax_error']);
            $this->assertSame(1, $file['order_count']);
            $this->assertTrue(Storage::disk('s3')->exists($file['file_path']));
        }
    }

    private function candidates(OrderChannel $channel = OrderChannel::FAX): Collection
    {
        $warehouse = Warehouse::query()->where('is_virtual', false)->firstOrFail();
        $item = Item::query()->firstOrFail();
        $suppliers = Supplier::query()->limit(2)->get();
        $this->assertCount(2, $suppliers, '専用テストDBに2件の仕入先マスタが必要です。');
        $contractor = Contractor::query()->create([
            'client_id' => $warehouse->client_id,
            'code' => 987654,
            'name' => 'FAX発注先テスト',
            'delivery_type' => 'ARRIVAL',
        ]);
        $batchCode = now()->format('YmdHis').'091';

        return WmsOrderCandidate::query()->whereIn('id', $suppliers->map(
            fn (Supplier $supplier, int $index): int => WmsOrderCandidate::create([
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'contractor_id' => $contractor->id,
                'supplier_id' => $supplier->id,
                'suggested_quantity' => $index + 2,
                'order_quantity' => $index + 2,
                'quantity_type' => QuantityType::CASE,
                'ordering_code' => '1234567890123',
                'expected_arrival_date' => '2026-10-15',
                'status' => CandidateStatus::CONFIRMED,
                'order_channel' => $channel,
            ])->id,
        ))->with('item')->get();
    }
}
