<?php

namespace Tests\Feature\AutoOrder;

use App\Filament\Pages\WmsOrderRegistration;
use App\Filament\Pages\WmsOrderRegistrationHq;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HqBulkContractorChangeTest extends TestCase
{
    private int $clientId = 1;

    private int $warehouse;

    private int $oldContractor;

    private int $targetContractor;

    private int $targetSupplier;

    private int $targetPartner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('sakemaru')->beginTransaction();
        $this->travelTo(now()->setDate(2026, 10, 29));
        $this->clientId = (int) DB::connection('sakemaru')->table('clients')->value('id') ?: $this->fixture('clients');
        config(['client.id' => $this->clientId]);
        $this->warehouse = $this->fixture('warehouses');
        if (! DB::connection('sakemaru')->table('warehouses')->where('code', 91)->exists()) {
            $this->fixture('warehouses', ['code' => 91]);
        }
        $oldSupplier = $this->fixture('suppliers', ['partner_id' => $this->fixture('partners')]);
        $this->oldContractor = $this->fixture('contractors', ['code' => 987451, 'supplier_id' => $oldSupplier]);
        $this->targetPartner = $this->fixture('partners');
        $this->targetSupplier = $this->fixture('suppliers', ['partner_id' => $this->targetPartner]);
        $this->targetContractor = $this->fixture('contractors', ['code' => 987452, 'name' => '一括変更先', 'supplier_id' => $this->targetSupplier]);
        $search = $this->partialMock(OrderRegistrationSearchService::class);
        $search->shouldReceive('incomingWarehouseId')->andReturn($this->warehouse);
        $search->shouldReceive('jxContractorIds')->andReturn([$this->targetContractor]);
        $search->shouldReceive('defaultExpectedArrivalDate')->andReturn('2026-11-02');
    }

    protected function tearDown(): void
    {
        DB::connection('sakemaru')->rollBack();
        $this->travelBack();
        parent::tearDown();
    }

    public function test_bulk_matches_individual_changes_and_only_changes_selected_visible_rows(): void
    {
        $lines = [$this->line('A', 1), $this->line('HIDDEN'), $this->line('UNSELECTED'), $this->line('B')];
        $lines[1]['contractor_id'] = $this->targetContractor;
        $lines[3]['order_channel'] = 'FAX';
        $page = $this->page($lines, ['0', '1', '3', '999']);
        $page->lineContractorFilter = (string) $this->oldContractor;
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);

        foreach ([0, 3] as $index) {
            $individual = $this->page($lines, []);
            $individual->applyLineContractorChange($index, $this->targetContractor, $this->targetSupplier, $this->warehouse);
            $this->assertSame($individual->lines[$index], $page->lines[$index]);
            $this->assertSame($this->targetSupplier, $page->lines[$index]['supplier_id']);
            $this->assertSame('2026-11-02', $page->lines[$index]['expected_arrival_date']);
            $this->assertSame($lines[$index]['order_quantity'], $page->lines[$index]['order_quantity']);
            $this->assertSame('PARTNER', $page->lines[$index]['purchase_unit_price_source']);
        }
        $this->assertSame(1299.0, $page->lines[0]['purchase_unit_price']);
        $this->assertSame(31176.0, $page->lines[3]['purchase_unit_price']);
        $this->assertSame('EOS', $page->lines[0]['order_channel']);
        $this->assertSame('FAX', $page->lines[3]['order_channel']);
        $this->assertSame($lines[1], $page->lines[1]);
        $this->assertSame($lines[2], $page->lines[2]);
        $this->assertSame([], $page->selectedRegistrationLineIndexes);
        $this->assertFalse($page->showBulkContractorChangeModal);
        $this->assertSame((string) $this->oldContractor, $page->lineContractorFilter);
    }

    public function test_same_contractor_keeps_the_existing_supplier_price_and_arrival_date(): void
    {
        $line = $this->line('SAME');
        $line['contractor_id'] = $this->targetContractor;
        $page = $this->page([$line]);
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame([$line], $page->lines);
    }

    public function test_unlinked_item_uses_the_default_supplier_and_becomes_fax(): void
    {
        $page = $this->page([$this->line('UNLINKED', 24, false)]);
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame($this->targetSupplier, $page->lines[0]['supplier_id']);
        $this->assertSame('FAX', $page->lines[0]['order_channel']);
        $this->assertFalse($page->lines[0]['is_eos_available']);
        $this->assertFalse($page->lines[0]['item_contractor_linked']);
        $this->assertStringContainsString('1件', collect(session('filament.notifications'))->last()['body']);
    }

    public function test_ambiguous_supplier_keeps_all_rows_unchanged_and_names_the_item(): void
    {
        $lines = [$this->line('VALID'), $this->line('AMBIGUOUS')];
        $supplier = $this->fixture('suppliers', ['partner_id' => $this->fixture('partners')]);
        $this->fixture('item_contractors', ['warehouse_id' => $this->warehouse, 'item_id' => $lines[1]['item_id'],
            'contractor_id' => $this->targetContractor, 'supplier_id' => $supplier]);
        $page = $this->page($lines, ['0', '1']);
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame($lines, $page->lines);
        $this->assertSame(['0', '1'], $page->selectedRegistrationLineIndexes);
        $this->assertTrue($page->showBulkContractorChangeModal);
        $this->assertStringContainsString('[AMBIGUOUS]', collect(session('filament.notifications'))->last()['title']);
        $this->assertStringContainsString('仕入先が複数', collect(session('filament.notifications'))->last()['title']);
    }

    public function test_missing_supplier_on_a_later_row_does_not_partially_apply(): void
    {
        $lines = [$this->line('VALID'), $this->line('NO-SUPPLIER', 24, false)];
        DB::connection('sakemaru')->table('contractors')->where('id', $this->targetContractor)->update(['supplier_id' => null]);
        $page = $this->page($lines, ['0', '1']);
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame($lines, $page->lines);
        $this->assertStringContainsString('[NO-SUPPLIER]', collect(session('filament.notifications'))->last()['title']);
    }

    public function test_search_normalizes_fullwidth_codes_and_limits_results_to_active_client_contractors(): void
    {
        $page = $this->page([$this->line('A')]);
        $this->fixture('contractors', ['code' => 987453, 'name' => '一括変更先', 'is_active' => 0]);
        $foreignClient = $this->fixture('clients');
        $this->fixture('contractors', ['client_id' => $foreignClient, 'code' => 987454, 'name' => '一括変更先']);
        $page->openBulkContractorChangeModal();
        $page->bulkContractorSearch = '９８７４５２';
        $page->searchBulkContractorChangeCandidates();
        $this->assertSame([$this->targetContractor], array_column($page->bulkContractorRows, 'id'));
        $page->bulkContractorSearch = '一括変更先';
        $page->searchBulkContractorChangeCandidates();
        $this->assertSame([$this->targetContractor], array_column($page->bulkContractorRows, 'id'));
    }

    public function test_changes_to_selection_or_contents_after_opening_require_reselection(): void
    {
        $lines = [$this->line('A'), $this->line('B')];
        foreach (['selection', 'quantity', 'removal', 'filter'] as $change) {
            $page = $this->page($lines, ['0', '1']);
            $page->openBulkContractorChangeModal();
            match ($change) {
                'selection' => $page->selectedRegistrationLineIndexes = ['0'],
                'quantity' => $page->lines[0]['order_quantity'] = 5,
                'removal' => array_pop($page->lines),
                'filter' => $page->lineContractorFilter = (string) $this->targetContractor,
            };
            $before = $page->lines;
            $page->applyBulkContractorChange($this->targetContractor);
            $this->assertSame($before, $page->lines);
            $this->assertStringContainsString('選び直し', collect(session('filament.notifications'))->last()['title']);
        }
    }

    public function test_empty_selection_and_cancel_never_change_lines(): void
    {
        $lines = [$this->line('A')];
        $page = $this->page($lines, []);
        $page->openBulkContractorChangeModal();
        $this->assertFalse($page->showBulkContractorChangeModal);
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame($lines, $page->lines);
        $page->selectedRegistrationLineIndexes = ['0'];
        $page->openBulkContractorChangeModal();
        $this->assertTrue($page->showBulkContractorChangeModal);
        $page->closeBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame($lines, $page->lines);
        $this->assertSame('', $page->bulkContractorSelectionHash);
        $this->assertSame([], $page->bulkContractorRows);
    }

    public function test_inactive_missing_and_foreign_contractors_cannot_be_applied(): void
    {
        $inactive = $this->fixture('contractors', ['is_active' => 0]);
        $foreign = $this->fixture('contractors', ['client_id' => $this->fixture('clients')]);
        $lines = [$this->line('A')];
        foreach ([$inactive, $foreign, 0] as $id) {
            $page = $this->page($lines);
            $page->openBulkContractorChangeModal();
            $page->applyBulkContractorChange($id);
            $this->assertSame($lines, $page->lines);
            $this->assertStringContainsString('有効な発注先', collect(session('filament.notifications'))->last()['title']);
        }
    }

    public function test_filter_is_cleared_when_the_last_matching_row_changes(): void
    {
        $page = $this->page([$this->line('A')]);
        $page->lineContractorFilter = (string) $this->oldContractor;
        $page->openBulkContractorChangeModal();
        $page->applyBulkContractorChange($this->targetContractor);
        $this->assertSame('', $page->lineContractorFilter);
        $this->assertSame($this->targetContractor, $page->lines[0]['contractor_id']);
    }

    public function test_regular_single_line_change_still_uses_the_same_case_price_and_failure_behavior(): void
    {
        $line = $this->line('REGULAR');
        $page = new WmsOrderRegistration;
        $page->warehouseId = $this->warehouse;
        $page->lines = [$line];
        $page->contractorChangeLineIndex = 0;
        $page->applyContractorChange($this->targetContractor, $this->targetSupplier, $this->warehouse);
        $this->assertSame(31176.0, $page->lines[0]['purchase_unit_price']);
        $this->assertSame('EOS', $page->lines[0]['order_channel']);
        $this->assertNull($page->contractorChangeLineIndex);
        $before = $page->lines;
        $page->contractorChangeLineIndex = 0;
        $page->applyContractorChange(0);
        $this->assertSame($before, $page->lines);
        $this->assertStringContainsString('変更できません', collect(session('filament.notifications'))->last()['title']);
    }

    private function page(array $lines, array $selection = ['0']): WmsOrderRegistrationHq
    {
        $page = new WmsOrderRegistrationHq;
        $page->warehouseId = $this->warehouse;
        $page->lines = $lines;
        $page->selectedRegistrationLineIndexes = $selection;

        return $page;
    }

    private function line(string $code, int $capacity = 24, bool $linked = true): array
    {
        $item = $this->fixture('items', ['capacity_case' => $capacity, 'purchase_price_type' => 'COST']);
        $this->fixture('item_prices', ['item_id' => $item, 'start_date' => '2026-10-01', 'cost_unit_price' => 100, 'cost_case_price' => 2400]);
        $this->fixture('item_partner_prices', ['item_id' => $item, 'partner_id' => $this->targetPartner,
            'warehouse_id' => $this->warehouse, 'start_date' => '2026-10-01', 'unit_price' => 1299, 'case_price' => 31176]);
        if ($linked) {
            $this->fixture('item_contractors', ['item_id' => $item, 'warehouse_id' => $this->warehouse,
                'contractor_id' => $this->targetContractor, 'supplier_id' => $this->targetSupplier, 'purchase_unit' => 1]);
        }

        return ['warehouse_id' => $this->warehouse, 'item_id' => $item, 'item_code' => $code, 'item_name' => '一括テスト'.$code,
            'contractor_id' => $this->oldContractor, 'contractor_name' => '変更前', 'supplier_id' => 1, 'supplier_partner_id' => 1,
            'purchase_unit_price' => 777, 'quantity_type' => 'CASE', 'order_quantity' => 12,
            'expected_arrival_date' => '2026-11-10', 'order_channel' => 'EOS', 'item_contractor_linked' => true];
    }

    /** Fill required fields from the dedicated test schema; every fixture is rolled back. */
    private function fixture(string $table, array $values = []): int
    {
        $db = DB::connection('sakemaru');
        $columns = $db->select('SELECT column_name,column_type,is_nullable,column_default,extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', [$table]);
        $defaults = [];
        foreach ($columns as $column) {
            $c = array_change_key_case((array) $column, CASE_LOWER);
            $name = $c['column_name'];
            if ($name === 'client_id') {
                $defaults[$name] = $this->clientId;
            }
            if ($name === 'is_active') {
                $defaults[$name] = 1;
            }
            if (str_contains($c['extra'], 'auto_increment') || $c['is_nullable'] === 'YES' || $c['column_default'] !== null || str_contains($c['extra'], 'GENERATED')) {
                continue;
            }
            $type = $c['column_type'];
            $defaults[$name] = match (true) {
                $name === 'client_id' => $this->clientId,
                $name === 'code' && str_contains($type, 'int') => random_int(700000000, 799999999),
                str_starts_with($type, 'enum(') => explode("'", $type)[1],
                str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'double'), str_contains($type, 'float') => 1,
                str_contains($type, 'date'), str_contains($type, 'timestamp') => '2026-10-21',
                $type === 'json' => '[]',
                default => 'hq-test-'.bin2hex(random_bytes(6)),
            };
        }

        return (int) $db->table($table)->insertGetId(array_merge($defaults, $values));
    }
}
