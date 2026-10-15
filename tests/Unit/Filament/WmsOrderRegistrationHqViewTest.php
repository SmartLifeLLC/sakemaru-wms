<?php

namespace Tests\Unit\Filament;

use App\Enums\EMenu;
use App\Enums\EMenuCategory;
use App\Filament\Pages\WmsOrderRegistration;
use App\Filament\Pages\WmsOrderRegistrationHq;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class WmsOrderRegistrationHqViewTest extends TestCase
{
    private const PAGE_VIEW = 'views/filament/pages/wms-order-registration-hq.blade.php';

    private const CANDIDATE_VIEW = 'views/filament/components/hq-order-registration-candidate-create-items.blade.php';

    private const PREVIEW_VIEW = 'views/filament/components/hq-order-registration-sales-preview-edit.blade.php';

    private const SELECTION_VIEW = 'views/filament/components/hq-order-registration-contractor-supplier-selection.blade.php';

    public function test_hq_menu_is_registered_in_order_processing_group(): void
    {
        $this->assertSame('外部発注（本部）', EMenu::WMS_ORDER_REGISTRATION_HQ->label());
        $this->assertSame(EMenuCategory::AUTO_ORDER, EMenu::WMS_ORDER_REGISTRATION_HQ->category());
    }

    public function test_new_external_order_stays_second_in_the_left_column_of_the_mega_menu(): void
    {
        // 発注処理は5件になり、メガメニューは2列（左→右、上→下）で並ぶ。
        // 左列は並び順の 1・3・5 番目なので、（新）外部発注は3番目に置く。
        $order = [
            EMenu::WMS_STOCK_TRANSFER_CANDIDATES,
            EMenu::WMS_ORDER_REGISTRATION_HQ,
            EMenu::WMS_ORDER_REGISTRATION,
            EMenu::WMS_ORDER_CANDIDATES,
            EMenu::WMS_ORDER_CONFIRMATION_WAITING,
        ];

        $sorted = $order;
        usort($sorted, fn (EMenu $a, EMenu $b): int => $a->sort() <=> $b->sort());

        $this->assertSame($order, $sorted);
        $this->assertCount(5, array_unique(array_map(fn (EMenu $menu): int => $menu->sort(), $order)));
    }

    public function test_existing_new_external_order_menu_is_unchanged(): void
    {
        $this->assertSame('（新）外部発注', EMenu::WMS_ORDER_REGISTRATION->label());
        $this->assertSame('（新）外部発注', WmsOrderRegistration::getNavigationLabel());
        $this->assertSame('wms-order-registration', WmsOrderRegistration::getSlug());
    }

    public function test_hq_page_is_a_separate_page_built_on_the_existing_registration(): void
    {
        $defaults = (new ReflectionClass(WmsOrderRegistrationHq::class))->getDefaultProperties();

        $this->assertTrue(is_subclass_of(WmsOrderRegistrationHq::class, WmsOrderRegistration::class));
        $this->assertSame('wms-order-registration-hq', WmsOrderRegistrationHq::getSlug());
        $this->assertSame('外部発注（本部）', WmsOrderRegistrationHq::getNavigationLabel());
        $this->assertSame(EMenu::WMS_ORDER_REGISTRATION_HQ->sort(), WmsOrderRegistrationHq::getNavigationSort());
        $this->assertSame('filament.pages.wms-order-registration-hq', $defaults['view']);
        $this->assertSame('wms-order-candidate', $defaults['permissionResource']);
    }

    #[DataProvider('candidateTableViews')]
    public function test_candidate_tables_show_last_incoming_and_last_purchase(string $view): void
    {
        $contents = file_get_contents(resource_path($view));
        $header = $this->candidateTableSection($contents, '<thead', '</thead>');
        $body = $this->candidateTableSection($contents, '<tbody', '</tbody>');

        foreach (['最終入荷予定日', '最終入荷予定数', '最終仕入日', '最終仕入数'] as $label) {
            $this->assertStringContainsString('label="'.$label.'"', $header);
        }

        foreach (['納品予定数', '見込在庫', '最終発注日', '納品予定日'] as $removedLabel) {
            $this->assertStringNotContainsString('label="'.$removedLabel.'"', $header);
        }

        $this->assertStringContainsString('last_incoming_date', $body);
        $this->assertStringContainsString('last_incoming_case_qty', $body);
        $this->assertStringContainsString('last_incoming_piece_qty', $body);
        $this->assertStringContainsString('last_purchase_date', $body);
        $this->assertStringContainsString('last_purchase_case_qty', $body);
        $this->assertStringContainsString('last_purchase_piece_qty', $body);
        $this->assertStringNotContainsString('projected_stock', $body);
        $this->assertStringNotContainsString('incoming_qty', $body);
    }

    #[DataProvider('candidateTableViews')]
    public function test_candidate_tables_keep_column_count_in_sync(string $view): void
    {
        $contents = file_get_contents(resource_path($view));
        $tablePosition = strpos($contents, '<table class="logistics-candidate-table');
        $this->assertNotFalse($tablePosition);
        $table = substr($contents, $tablePosition);

        $colgroup = $this->between($table, '<colgroup>', '</colgroup>');
        $header = $this->between($table, '<thead', '</thead>');

        $this->assertSame(substr_count($colgroup, '<col '), substr_count($header, '<th '));
    }

    #[DataProvider('candidateTableViews')]
    public function test_expected_arrival_date_is_displayed_after_previous_month(string $view): void
    {
        $contents = file_get_contents(resource_path($view));
        $header = $this->candidateTableSection($contents, '<thead', '</thead>');

        $this->assertLessThan(strpos($header, 'label="予定日"'), strpos($header, 'label="前月"'));
        $this->assertLessThan(strpos($header, 'label="1週"'), strpos($header, 'label="最終仕入日"'));
    }

    public function test_sales_preview_displays_period_total_before_weekly_sales(): void
    {
        $contents = file_get_contents(resource_path(self::PREVIEW_VIEW));
        $header = $this->between($contents, '<thead', '</thead>');

        $this->assertLessThan(strpos($header, 'label="1週"'), strpos($header, 'label="実績合計"'));
        $this->assertStringContainsString("conditionValue('supplier_count')", $contents);
    }

    public function test_registration_list_uses_contractor_pulldown_instead_of_change_button(): void
    {
        $contents = file_get_contents(resource_path(self::PAGE_VIEW));

        $this->assertStringContainsString('data-hq-contractor-picker-trigger', $contents);
        $this->assertStringContainsString('$wire.applyLineContractorChange(', $contents);
        $this->assertStringContainsString('$wire.searchCandidateContractorChangeOptions(', $contents);
        $this->assertStringNotContainsString('openContractorChangeModal', $contents);
        $this->assertStringNotContainsString('$showContractorChangeModal', $contents);
    }

    public function test_registration_list_shows_reference_columns_and_delivery_warehouse_code_only(): void
    {
        $contents = file_get_contents(resource_path(self::PAGE_VIEW));
        $table = $this->between($contents, '@forelse ($visibleLines as $index => $line)', '登録リストは空です');
        $start = strpos($contents, '<th class="whitespace-nowrap px-2 py-2 text-center">行</th>');
        $this->assertNotFalse($start);
        $header = substr($contents, $start, strpos($contents, '</thead>', $start) - $start);

        foreach (['発注点', '理論在庫', '最終入荷予定', '最終仕入', '1週', '2週', '3週', '前月', '納入先'] as $label) {
            $this->assertStringContainsString('>'.$label.'</th>', $header);
        }

        $this->assertLessThan(strpos($header, '>総バラ数</th>'), strpos($header, '>総ケース数</th>'));
        $this->assertSame(23, substr_count($header, '<th ') + 1, '登録リストの列数が空行の colspan と合っていません。');
        $this->assertStringContainsString('colspan="23"', $contents);

        // 商品は「[商品CD] 商品名」を1列（幅固定・自動改行）で表示し、容量x入数（例: 750ml x 6）を別列で出す。
        $this->assertStringContainsString('>[商品CD]商品名</th>', $header);
        $this->assertStringContainsString('>容量x入数</th>', $header);
        $this->assertStringNotContainsString('>商品CD</th>', $header);
        $this->assertStringNotContainsString('>規格</th>', $header);
        $this->assertStringNotContainsString('>入数</th>', $header);
        $this->assertStringContainsString("[{{ \$line['item_code'] }}]", $table);
        $this->assertStringContainsString('w-56 whitespace-normal', $table);
        $this->assertStringContainsString("\$reference['volume_label']", $table);
        $this->assertStringContainsString('{{ $volumeCapacityLabel }}', $table);

        // 納入先は発注先の前。発注先名は発注先CDの下に省略せず表示する（発注先名だけの列は持たない）。
        $this->assertLessThan(strpos($header, '>発注先</th>'), strpos($header, '>納入先</th>'));
        $this->assertStringNotContainsString('>発注先名</th>', $header);
        $this->assertStringNotContainsString('truncate', $table);
        $this->assertLessThan(strpos($table, 'data-hq-contractor-picker-trigger'), strpos($table, '>{{ $warehouseCode }}</span>'));
        $this->assertLessThan(strpos($table, "{{ \$line['contractor_name'] }}</div>"), strpos($table, 'data-hq-contractor-picker-trigger'));

        // 納入先はコードのみ（倉庫名はマウスオーバーのツールチップで表示）
        $this->assertStringContainsString('x-tooltip="{ content: @js($warehouseName), theme: $store.theme }"', $table);
        $this->assertStringContainsString('>{{ $warehouseCode }}</span>', $table);
        $this->assertStringNotContainsString('{{ $warehouseName }}</div>', $table);
    }

    public function test_hq_page_uses_hq_components_only_for_changed_parts(): void
    {
        $contents = file_get_contents(resource_path(self::PAGE_VIEW));

        $this->assertStringContainsString("@include('filament.components.hq-order-registration-candidate-create-items'", $contents);
        $this->assertStringContainsString("@include('filament.components.hq-order-registration-sales-preview-edit'", $contents);
        $this->assertStringContainsString("@include('filament.components.hq-order-registration-contractor-supplier-selection'", $contents);
        $this->assertStringNotContainsString("@include('filament.components.order-registration-candidate-create-items'", $contents);
        $this->assertStringNotContainsString("@include('filament.components.order-registration-sales-preview-edit'", $contents);
        $this->assertStringNotContainsString("@include('filament.components.order-registration-contractor-selection'", $contents);
    }

    public function test_modals_are_layered_above_the_mega_menu(): void
    {
        $contents = file_get_contents(resource_path(self::PAGE_VIEW));

        // ページ本体は .fi-page-header-main-ctn（z-index: 60）の中にあり、メガメニューは z-index: 100。
        // モーダル表示中だけ入れ物ごと前面に上げるので、全画面モーダルには data-hq-modal が必要。
        $this->assertMatchesRegularExpression(
            '/\.fi-page-header-main-ctn:has\(\[data-hq-modal\]\)\s*\{\s*z-index:\s*9999\s*!important;/',
            $contents,
        );

        preg_match_all('/<div[^>]*class="fixed inset-0 flex[^"]*"[^>]*>/', $contents, $matches);

        $this->assertCount(7, $matches[0]);
        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('data-hq-modal', $tag);
        }
    }

    public function test_generation_modal_selects_contractors_and_linked_suppliers(): void
    {
        $contents = file_get_contents(resource_path(self::SELECTION_VIEW));

        $this->assertStringContainsString("\$wire.\$entangle('selectedExternalOrderContractorIds')", $contents);
        $this->assertStringContainsString("\$wire.\$entangle('selectedHqSupplierIds')", $contents);
        $this->assertStringContainsString('data-hq-contractor-list', $contents);
        $this->assertStringContainsString('data-hq-supplier-list', $contents);
        $this->assertLessThan(strpos($contents, 'data-hq-supplier-list'), strpos($contents, 'data-hq-contractor-list'));
        $this->assertStringNotContainsString('EOS発注先', $contents);
        $this->assertStringNotContainsString('FAX発注先', $contents);
    }

    public function test_existing_views_do_not_reference_hq_components(): void
    {
        $existingViews = [
            'views/filament/pages/wms-order-registration.blade.php',
            'views/filament/components/order-registration-candidate-create-items.blade.php',
            'views/filament/components/order-registration-sales-preview-edit.blade.php',
            'views/filament/components/order-registration-contractor-selection.blade.php',
        ];

        foreach ($existingViews as $view) {
            $contents = file_get_contents(resource_path($view));

            $this->assertStringNotContainsString('hq-order-registration', $contents, $view);
            $this->assertStringNotContainsString('selectedHqSupplierIds', $contents, $view);
        }
    }

    public static function candidateTableViews(): array
    {
        return [
            'candidate search' => [self::CANDIDATE_VIEW],
            'sales preview' => [self::PREVIEW_VIEW],
        ];
    }

    private function between(string $contents, string $start, string $end): string
    {
        $startPosition = strpos($contents, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($contents, $end, $startPosition);
        $this->assertNotFalse($endPosition);

        return substr($contents, $startPosition, $endPosition - $startPosition);
    }

    private function candidateTableSection(string $contents, string $start, string $end): string
    {
        $tablePosition = strpos($contents, '<table class="logistics-candidate-table');
        $this->assertNotFalse($tablePosition);

        return $this->between(substr($contents, $tablePosition), $start, $end);
    }
}
