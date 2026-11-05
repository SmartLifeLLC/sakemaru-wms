<?php

namespace Tests\Unit\Filament;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WmsOrderRegistrationCandidateViewTest extends TestCase
{
    #[DataProvider('candidateTableViews')]
    public function test_expected_arrival_date_is_displayed_after_previous_month(string $view): void
    {
        $contents = file_get_contents(resource_path($view));
        $header = $this->candidateTableSection($contents, '<thead', '</thead>');

        $this->assertStringContainsString('label="前月"', $header);
        $this->assertStringContainsString('label="予定日"', $header);
        $this->assertLessThan(
            strpos($header, 'label="予定日"'),
            strpos($header, 'label="前月"')
        );
    }

    public function test_sales_preview_displays_period_total_before_weekly_sales(): void
    {
        $contents = file_get_contents(resource_path('views/filament/components/order-registration-sales-preview-edit.blade.php'));
        $header = $this->between($contents, '<thead', '</thead>');
        $body = $this->between($contents, '<tbody', '</tbody>');

        $this->assertLessThan(strpos($header, 'label="1週"'), strpos($header, 'label="実績合計"'));
        $this->assertLessThan(strpos($body, 'row.sales_week1_qty'), strpos($body, 'row.sales_qty'));
    }

    public function test_candidate_search_explains_supported_product_identifiers(): void
    {
        $contents = file_get_contents(resource_path('views/filament/components/order-registration-candidate-create-items.blade.php'));

        $this->assertStringContainsString('JANコード・自社コード等', $contents);
        $this->assertStringContainsString('単品CD・自社CDも検索可', $contents);
    }

    public static function candidateTableViews(): array
    {
        return [
            'candidate search' => ['views/filament/components/order-registration-candidate-create-items.blade.php'],
            'sales preview' => ['views/filament/components/order-registration-sales-preview-edit.blade.php'],
        ];
    }

    private function between(string $contents, string $start, string $end): string
    {
        $startPosition = strpos($contents, $start);
        $endPosition = strpos($contents, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition);
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
