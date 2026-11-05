<?php

namespace Tests\Unit\Filament;

use Tests\TestCase;

/**
 * 店間発注の入力画面がケース/バラの両方を入力でき、総バラ数を表示する構成になっていること。
 */
class StockTransferCaseOrderViewTest extends TestCase
{
    private const CREATE_VIEW = 'views/filament/components/transfer-order-create-items.blade.php';

    private const PREVIEW_VIEW = 'views/filament/components/sales-based-transfer-preview-edit.blade.php';

    private const QUANTITY_EDIT_VIEW = 'views/filament/components/stock-transfer-candidate-quantity-input.blade.php';

    private const NEW_EXTERNAL_ORDER_VIEW = 'views/filament/components/order-registration-candidate-create-items.blade.php';

    public function test_code_search_field_uses_the_same_label_as_new_external_order(): void
    {
        $view = $this->readView(self::CREATE_VIEW);

        $this->assertStringContainsString('JANコード・自社コード等', $view);
        $this->assertStringContainsString('単品CD・自社CDも検索可', $view);

        $externalPath = resource_path(self::NEW_EXTERNAL_ORDER_VIEW);
        if (is_file($externalPath)) {
            $external = (string) file_get_contents($externalPath);
            $this->assertStringContainsString('JANコード・自社コード等', $external);
            $this->assertStringContainsString('単品CD・自社CDも検索可', $external);
        }
    }

    public function test_manual_add_modal_has_case_and_piece_inputs_that_exclude_each_other(): void
    {
        $view = $this->readView(self::CREATE_VIEW);

        $this->assertStringContainsString("setQty(item, 'caseQty', 'pieceQty', \$event.target.value)", $view);
        $this->assertStringContainsString("setQty(item, 'pieceQty', 'caseQty', \$event.target.value)", $view);
        $this->assertStringContainsString('totalPieces(item)', $view);
        $this->assertStringContainsString('総バラ', $view);
        // 送信内容はケース・バラのどちらか一方だけに数量が入る
        $this->assertStringContainsString('case_qty: caseQty > 0 ? caseQty : 0', $view);
        $this->assertStringContainsString('piece_qty: caseQty > 0 ? 0 : pieceQty', $view);
    }

    public function test_sales_preview_modal_has_case_and_piece_inputs(): void
    {
        $view = $this->readView(self::PREVIEW_VIEW);

        $this->assertStringContainsString('x-model="row.input_order_case_qty"', $view);
        $this->assertStringContainsString('x-model="row.input_order_piece_qty"', $view);
        $this->assertStringContainsString("cleanQuantity(row, 'input_order_case_qty', 'input_order_piece_qty')", $view);
        $this->assertStringContainsString("cleanQuantity(row, 'input_order_piece_qty', 'input_order_case_qty')", $view);
        $this->assertStringContainsString('totalPieces(row)', $view);
        $this->assertStringContainsString('総バラ', $view);
    }

    public function test_quantity_edit_modal_sends_quantity_with_its_unit(): void
    {
        $view = $this->readView(self::QUANTITY_EDIT_VIEW);

        $this->assertStringContainsString('x-model="row.case_qty"', $view);
        $this->assertStringContainsString('x-model="row.piece_qty"', $view);
        $this->assertStringContainsString("quantity_type: 'CASE'", $view);
        $this->assertStringContainsString("quantity_type: 'PIECE'", $view);
        $this->assertStringContainsString('...this.resolvedQuantity(row)', $view);
        $this->assertStringContainsString('総バラ', $view);
    }

    public function test_case_input_is_disabled_for_items_without_capacity(): void
    {
        $this->assertStringContainsString(':disabled="item.can_order_case === false"', $this->readView(self::CREATE_VIEW));
        $this->assertStringContainsString('x-bind:disabled="row.can_order_case === false"', $this->readView(self::PREVIEW_VIEW));
        $this->assertStringContainsString('x-bind:disabled="row.disabled || row.can_order_case === false"', $this->readView(self::QUANTITY_EDIT_VIEW));
    }

    private function readView(string $path): string
    {
        $fullPath = resource_path($path);
        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }
}
