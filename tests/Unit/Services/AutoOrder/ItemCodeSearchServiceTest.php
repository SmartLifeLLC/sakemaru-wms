<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\ItemCodeSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 店間発注「個別発注を追加」のコード検索（新外部発注と同じ探し方）のテスト。
 */
class ItemCodeSearchServiceTest extends TestCase
{
    #[DataProvider('searchCodeCases')]
    public function test_search_text_is_split_into_codes(?string $input, array $expected): void
    {
        $this->assertSame($expected, (new ItemCodeSearchService)->normalizeDelimitedSearchCodes($input));
    }

    public static function searchCodeCases(): array
    {
        return [
            '未入力' => [null, []],
            '空白のみ' => ['   ', []],
            '1件' => ['4901004006714', ['4901004006714']],
            '前後の空白を除く' => ['  12345 ', ['12345']],
            'スペース区切り' => ['111 222', ['111', '222']],
            'カンマ区切り' => ['111,222', ['111', '222']],
            '読点・全角カンマ区切り' => ['111、222，333', ['111', '222', '333']],
            'スラッシュ区切り' => ['111/222／333', ['111', '222', '333']],
            '改行区切り' => ["111\n222", ['111', '222']],
            '全角数字は半角にする' => ['１２３４５', ['12345']],
            '全角スペース区切り' => ['111　222', ['111', '222']],
            '重複は1つにまとめる' => ['111 222 111', ['111', '222']],
        ];
    }

    public function test_no_codes_resolve_to_nothing_without_touching_the_database(): void
    {
        $this->assertSame([[], []], (new ItemCodeSearchService)->resolveExactCodeSearches([]));
    }
}
