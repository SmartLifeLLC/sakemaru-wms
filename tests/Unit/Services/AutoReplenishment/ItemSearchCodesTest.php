<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Pages\AutoReplenishmentPage;
use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\ItemSearchCodes;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class ItemSearchCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code TEXT, name TEXT, capacity_case INTEGER, is_active INTEGER, is_ended INTEGER)');
        $db->statement('CREATE TABLE item_search_information (item_id INTEGER, client_id INTEGER, search_string TEXT, is_active INTEGER)');
        $db->statement('CREATE TABLE item_contractors (item_id INTEGER, client_id INTEGER, warehouse_id INTEGER, contractor_id INTEGER)');
        $db->statement('CREATE TABLE contractors (id INTEGER, client_id INTEGER, is_active INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER)');
        foreach ([1 => 'アサヒ', 2 => 'サントリー', 3 => '対象外'] as $id => $name) {
            $db->table('items')->insert(['id' => $id, 'client_id' => 6, 'code' => (string) $id, 'name' => $name, 'capacity_case' => 24, 'is_active' => 1, 'is_ended' => 0]);
            $db->table('wms_item_reorder_policies')->insert(['id' => $id, 'client_id' => 6, 'warehouse_id' => 10, 'item_id' => $id]);
        }
        $db->table('item_search_information')->insert([
            ['item_id' => 1, 'client_id' => 6, 'search_string' => '4901234567890', 'is_active' => 1],
            ['item_id' => 1, 'client_id' => 6, 'search_string' => '00123', 'is_active' => 1],
            ['item_id' => 1, 'client_id' => 6, 'search_string' => 'AB%_!CD', 'is_active' => 1],
            ['item_id' => 2, 'client_id' => 6, 'search_string' => '4999876543210', 'is_active' => 1],
            ['item_id' => 3, 'client_id' => 6, 'search_string' => '4901234567890', 'is_active' => 0],
            ['item_id' => 3, 'client_id' => 7, 'search_string' => '4901234567890', 'is_active' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function ids(string $input, ?string $name = null): array
    {
        $q = DB::connection('sakemaru')->table('items')->where('client_id', 6);
        if ($name !== null) {
            $q->where('name', 'like', '%'.$name.'%');
        }
        app(ItemSearchCodes::class)->apply($q, 6, $input, 'items.id');

        return $q->orderBy('id')->pluck('id')->all();
    }

    public function test_multiple_codes_match_either_code_and_preserve_leading_zeros_and_full_width_input(): void
    {
        self::assertSame(['00123', '4999', '4901'], app(ItemSearchCodes::class)->terms('００１２３、4999／4901,00123'));
        self::assertSame([1, 2], $this->ids('００１２３ 4999'));
        self::assertSame([1], $this->ids('123456'));
    }

    public function test_inactive_and_other_client_codes_are_excluded_and_duplicate_matches_do_not_duplicate_products(): void
    {
        self::assertSame([1], $this->ids('4901,00123'));
        self::assertSame([1], $this->ids('00123,00123'));
        self::assertSame([], $this->ids('no-match'));
    }

    public function test_name_and_existing_scope_are_not_bypassed_by_or_codes(): void
    {
        self::assertSame([1], $this->ids('00123,4999', 'アサヒ'));
        self::assertSame([], $this->ids('4999', 'アサヒ'));
        $db = DB::connection('sakemaru');
        $db->table('contractors')->insert(['id' => 100, 'client_id' => 6, 'is_active' => 1]);
        $db->table('item_contractors')->insert([
            ['item_id' => 1, 'client_id' => 6, 'warehouse_id' => 10, 'contractor_id' => 100],
            ['item_id' => 2, 'client_id' => 6, 'warehouse_id' => 20, 'contractor_id' => 100],
        ]);
        $page = new ItemSearchCodesPageFixture;
        $page->warehouseId = 10;
        $method = new \ReflectionMethod(AutoReplenishmentPage::class, 'itemSelectionQuery');
        $query = $method->invoke($page, 6, [100]);
        app(ItemSearchCodes::class)->apply($query, 6, '4901,4999', 'items.id');
        self::assertSame([1], $query->pluck('id')->all());
        $db->table('items')->where('id', 1)->update(['is_ended' => 1]);
        self::assertSame([], $query->pluck('id')->all());
    }

    public function test_wildcards_are_literal_and_blank_input_does_not_add_a_code_lookup(): void
    {
        self::assertSame([1], $this->ids('%_!'));
        self::assertSame([], $this->ids("' OR 1=1 --"));
        DB::connection('sakemaru')->enableQueryLog();
        self::assertSame([1, 2, 3], $this->ids(' ,、／ '));
        $last = DB::connection('sakemaru')->getQueryLog();
        self::assertStringNotContainsString('item_search_information', $last[0]['query']);
    }

    public function test_registered_policy_filter_uses_the_same_codes_and_only_reads(): void
    {
        DB::connection('sakemaru')->enableQueryLog();
        $q = WmsItemReorderPolicy::query()->where('client_id', 6)->where('warehouse_id', 10);
        app(ItemSearchCodes::class)->apply($q, 6, '00123,4999', 'wms_item_reorder_policies.item_id');
        self::assertSame([1, 2], $q->orderBy('id')->pluck('item_id')->all());
        foreach (DB::connection('sakemaru')->getQueryLog() as $query) {
            self::assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_picker_can_search_by_jan_alone_then_narrow_by_name_and_clear(): void
    {
        Livewire::test(ItemSearchCodesPickerFixture::class)
            ->assertSee('JAN・自社CD')->assertSee('商品CD・商品名、またはJAN・自社CDを入力してください。')
            ->set('itemJanSearch', '００１２３,4999')->assertSee('アサヒ')->assertSee('サントリー')->assertDontSee('対象外')
            ->set('itemSearch', 'アサヒ')->assertSee('アサヒ')->assertDontSee('サントリー')
            ->set('itemJanSearch', 'missing')->assertSee('該当する商品がありません。')
            ->set('itemSearch', '')->set('itemJanSearch', '')->assertSee('商品CD・商品名、またはJAN・自社CDを入力してください。');
    }

    public function test_picker_keeps_the_first_thirty_results_and_reports_more(): void
    {
        $db = DB::connection('sakemaru');
        foreach (range(10, 40) as $id) {
            $db->table('items')->insert(['id' => $id, 'client_id' => 6, 'code' => (string) $id, 'name' => 'テスト商品'.$id, 'capacity_case' => 24]);
            $db->table('item_search_information')->insert(['item_id' => $id, 'client_id' => 6, 'search_string' => 'many-'.$id, 'is_active' => 1]);
        }
        Livewire::test(ItemSearchCodesPickerFixture::class)->set('itemJanSearch', 'many-')
            ->assertSee('先頭30件を表示')->assertSee('テスト商品39')->assertDontSee('テスト商品40');
    }
}

class ItemSearchCodesPageFixture extends AutoReplenishmentPage {}

class ItemSearchCodesPickerFixture extends Component
{
    public bool $showItemPicker = true;

    public string $itemSearch = '';

    public string $itemJanSearch = '';

    public array $form = ['is_enabled' => false];

    public function render()
    {
        $itemSearchTerm = trim($this->itemSearch);
        $itemJanSearchTerm = implode(' ', app(ItemSearchCodes::class)->terms($this->itemJanSearch));
        $q = DB::connection('sakemaru')->table('items')->where('client_id', 6);
        if ($itemSearchTerm !== '') {
            $q->where('name', 'like', '%'.$itemSearchTerm.'%');
        }
        app(ItemSearchCodes::class)->apply($q, 6, $this->itemJanSearch, 'items.id');
        $itemOptions = $q->orderBy('code')->limit(31)->get();
        $itemSearchHasMore = $itemOptions->count() > 30;
        $itemOptions = $itemOptions->take(30);

        return view('filament.pages.auto-replenishment-item-picker', compact('itemSearchTerm', 'itemJanSearchTerm', 'itemOptions', 'itemSearchHasMore') + ['helperItem' => null]);
    }
}
