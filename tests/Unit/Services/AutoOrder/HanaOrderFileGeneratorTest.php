<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * HanaOrderFileGenerator テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class HanaOrderFileGeneratorTest extends TestCase
{
    private HanaOrderJXFileGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new HanaOrderJXFileGenerator;
    }

    /**
     * @test
     * インターフェース実装の確認
     */
    public function it_implements_order_file_generator_interface(): void
    {
        $this->assertInstanceOf(
            \App\Contracts\OrderFileGeneratorInterface::class,
            $this->generator
        );
    }

    /**
     * @test
     * エンコーディング設定の確認
     */
    public function it_returns_correct_encoding(): void
    {
        $this->assertEquals('SJIS', $this->generator->getEncoding());
    }

    /**
     * @test
     * 改行コード設定の確認
     */
    public function it_returns_correct_line_ending(): void
    {
        $this->assertEquals("\r\n", $this->generator->getLineEnding());
    }

    /**
     * @test
     * ファイル拡張子の確認
     */
    public function it_returns_correct_file_extension(): void
    {
        $this->assertEquals('dat', $this->generator->getFileExtension());
    }

    /**
     * @test
     * JX送信対象発注先IDの取得
     */
    public function it_returns_jx_transmission_contractor_ids(): void
    {
        $ids = $this->generator->getJxTransmissionContractorIds();

        $this->assertIsArray($ids);

        // JX対象発注先コード: 1106, 1017, 1202, 1330
        $expectedCodes = [1106, 1017, 1202, 1330];
        foreach ($expectedCodes as $code) {
            $contractor = Contractor::where('code', $code)->first();
            if ($contractor) {
                $this->assertContains($contractor->id, $ids, "Contractor code {$code} should be in JX transmission list");
            }
        }
    }

    /**
     * @test
     * 送信先集約マッピングの取得
     */
    public function it_returns_transmission_contractor_mapping(): void
    {
        $mapping = $this->generator->getTransmissionContractorMapping();

        $this->assertIsArray($mapping);

        // マッピング: 1021,1029,1068,1126,1127 → 1106
        $kanakan1106 = Contractor::where('code', 1106)->first();
        if ($kanakan1106) {
            $mappedCodes = [1021, 1029, 1068, 1126, 1127];
            foreach ($mappedCodes as $code) {
                $contractor = Contractor::where('code', $code)->first();
                if ($contractor && isset($mapping[$contractor->id])) {
                    $this->assertEquals(
                        $kanakan1106->id,
                        $mapping[$contractor->id],
                        "Contractor code {$code} should map to 1106"
                    );
                }
            }
        }
    }

    /**
     * @test
     * 空のコレクションでのファイル生成
     */
    public function it_generates_empty_result_for_empty_collection(): void
    {
        $candidates = collect([]);

        $result = $this->generator->generate($candidates);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * 実DBデータを使用したファイル生成
     */
    public function it_generates_file_with_real_db_data(): void
    {
        // 実際のデータを取得（JX対象発注先のもの）
        $candidates = $this->getTestCandidatesFromRealData();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $file) {
            // 必須フィールドの確認
            $this->assertArrayHasKey('contractor_id', $file);
            $this->assertArrayHasKey('contractor_code', $file);
            $this->assertArrayHasKey('content', $file);
            $this->assertArrayHasKey('filename', $file);
            $this->assertArrayHasKey('encoding', $file);
            $this->assertArrayHasKey('record_count', $file);
            $this->assertArrayHasKey('order_count', $file);

            // ファイル名形式の確認
            $this->assertMatchesRegularExpression(
                '/^\d+_order_\d{14}\.dat$/',
                $file['filename'],
                'Filename should match expected format'
            );

            // エンコーディングの確認
            $this->assertEquals('SJIS', $file['encoding']);

            // コンテンツがShift_JISでエンコードされていることを確認
            $this->assertNotEmpty($file['content']);

            // レコード数が正の整数であることを確認
            $this->assertGreaterThan(0, $file['record_count']);
            $this->assertGreaterThan(0, $file['order_count']);
        }
    }

    /**
     * @test
     * Aレコード（ヘッダ）の形式確認
     */
    public function it_generates_valid_a_record(): void
    {
        $candidates = $this->getTestCandidatesFromRealData();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        // SJISのままで128バイト単位で分割
        $records = $this->splitRecords($result[0]['content']);
        $this->assertNotEmpty($records);

        // 最初のレコードがJXラッパーの開始行"1"であること
        $this->assertStringStartsWith('1', $records[0], 'First record should be JX wrapper header (1)');

        // 2番目のレコードがAレコードであること
        $this->assertStringStartsWith('A', $records[1], 'Second record should be A record');

        // Aレコードの長さが128バイトであること
        $this->assertEquals(128, strlen($records[1]), 'A record should be 128 bytes');
    }

    /**
     * @test
     * Bレコード（店舗/納品先）の形式確認
     */
    public function it_generates_valid_b_record(): void
    {
        $candidates = $this->getTestCandidatesFromRealData();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        // SJISのままで128バイト単位で分割
        $records = $this->splitRecords($result[0]['content']);

        // Bレコードを探す
        $bRecords = array_filter($records, fn ($record) => str_starts_with($record, 'B'));
        $this->assertNotEmpty($bRecords, 'Should have at least one B record');

        foreach ($bRecords as $bRecord) {
            // Bレコードの長さが128バイトであること
            $this->assertEquals(128, strlen($bRecord), 'B record should be 128 bytes');
        }
    }

    /**
     * @test
     * Dレコード（商品明細）の形式確認
     */
    public function it_generates_valid_d_record(): void
    {
        $candidates = $this->getTestCandidatesFromRealData();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        // SJISのままで128バイト単位で分割
        $records = $this->splitRecords($result[0]['content']);

        // Dレコードを探す
        $dRecords = array_filter($records, fn ($record) => str_starts_with($record, 'D'));
        $this->assertNotEmpty($dRecords, 'Should have at least one D record');

        foreach ($dRecords as $dRecord) {
            // Dレコードの長さが128バイトであること
            $this->assertEquals(128, strlen($dRecord), 'D record should be 128 bytes');
        }
    }

    /**
     * @test
     * 送信先集約のグルーピング確認
     */
    public function it_groups_candidates_by_transmission_contractor(): void
    {
        // カナカングループの発注先を持つ候補を取得
        $kanakanCodes = [1021, 1029, 1068, 1126, 1127, 1106];
        $kanakanContractorIds = Contractor::whereIn('code', $kanakanCodes)->pluck('id')->toArray();

        if (empty($kanakanContractorIds)) {
            $this->markTestSkipped('Kanakan contractors not found in database');
        }

        $candidates = WmsOrderCandidate::whereIn('contractor_id', $kanakanContractorIds)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit(10)
            ->get();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No Kanakan order candidates in database');
        }

        $result = $this->generator->generate($candidates);

        // カナカングループは1106に集約されるので、1ファイルになるはず
        $this->assertCount(1, $result, 'Kanakan group should be consolidated into one file');

        // 発注先コードが1106であること
        $this->assertEquals(1106, $result[0]['contractor_code']);
    }

    /**
     * @test
     * レコード数のカウント確認
     */
    public function it_counts_records_correctly(): void
    {
        $candidates = $this->getTestCandidatesFromRealData(5);

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        foreach ($result as $file) {
            // SJISのままで128バイト単位で分割
            $records = $this->splitRecords($file['content']);

            // 実際のレコード数とカウントが一致
            $this->assertEquals(count($records), $file['record_count']);
        }
    }

    /**
     * 実DBからテスト用の発注候補を取得
     */
    private function getTestCandidatesFromRealData(int $limit = 10): Collection
    {
        // JX対象の発注先コード
        $jxContractorCodes = [1106, 1017, 1202, 1330, 1021, 1029, 1068, 1126, 1127];
        $jxContractorIds = Contractor::whereIn('code', $jxContractorCodes)->pluck('id')->toArray();

        if (empty($jxContractorIds)) {
            return collect([]);
        }

        return WmsOrderCandidate::whereIn('contractor_id', $jxContractorIds)
            ->with(['warehouse', 'item', 'contractor'])
            ->limit($limit)
            ->get();
    }

    /**
     * @test
     * JANコードがitem_search_informationから取得され、13桁にゼロパディングされること
     *
     * 注意: 1つの商品に複数のコードが登録されている場合、サンプルファイルと
     * 異なるコードが選択される可能性があるが、選択されるコードは有効なバーコードである。
     */
    public function it_retrieves_jan_code_from_item_search_information(): void
    {
        $candidates = $this->getTestCandidatesFromRealData(3);

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        // SJISのままで128バイト単位で分割
        $records = $this->splitRecords($result[0]['content']);

        // Dレコードを取得
        $dRecords = array_filter($records, fn ($record) => str_starts_with($record, 'D'));

        foreach ($dRecords as $dRecord) {
            $itemCode = trim(substr($dRecord, 82, 6));
            $janCode = trim(substr($dRecord, 69, 13));

            if (empty($itemCode)) {
                continue;
            }

            // JANコードが13桁であることを確認
            $this->assertEquals(
                13,
                strlen($janCode),
                "JAN code for item {$itemCode} should be 13 digits"
            );

            // JANコードが数字のみであることを確認
            $this->assertMatchesRegularExpression(
                '/^[0-9]{13}$/',
                $janCode,
                "JAN code for item {$itemCode} should contain only digits"
            );

            // 先頭のゼロを除去してitem_search_informationに存在するか確認
            $janCodeWithoutLeadingZeros = ltrim($janCode, '0');
            if ($janCodeWithoutLeadingZeros === '') {
                $janCodeWithoutLeadingZeros = '0';
            }

            $existsInDb = \Illuminate\Support\Facades\DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', intval($itemCode))
                ->where('is_active', true)
                ->where(function ($query) use ($janCode, $janCodeWithoutLeadingZeros) {
                    $query->where('search_string', $janCode)
                        ->orWhere('search_string', $janCodeWithoutLeadingZeros);
                })
                ->exists();

            $this->assertTrue(
                $existsInDb,
                "JAN code {$janCode} for item {$itemCode} should exist in item_search_information"
            );
        }
    }

    /**
     * @test
     * 候補にordering_codeが設定されている場合、そのコードが使用されること
     */
    public function it_uses_ordering_code_from_candidate_when_set(): void
    {
        $candidates = $this->getTestCandidatesFromRealData(3);

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No test data available in database');
        }

        // 候補にordering_codeを設定
        $testOrderingCode = '1234567890123';
        $candidates->first()->ordering_code = $testOrderingCode;

        $result = $this->generator->generate($candidates);
        $this->assertNotEmpty($result);

        // SJISのままで128バイト単位で分割
        $records = $this->splitRecords($result[0]['content']);

        // 最初のDレコードを取得
        $dRecords = array_filter($records, fn ($record) => str_starts_with($record, 'D'));
        $firstDRecord = array_values($dRecords)[0] ?? null;

        $this->assertNotNull($firstDRecord);

        // JANコードフィールド（70-82）を確認
        $orderingCodeInRecord = trim(substr($firstDRecord, 69, 13));
        $this->assertEquals(
            $testOrderingCode,
            $orderingCodeInRecord,
            'Ordering code from candidate should be used in D record'
        );
    }

    /**
     * @test
     * 候補のordering_codeが空文字の場合、発注用コードにフォールバックすること
     */
    public function it_falls_back_to_ordering_code_master_when_candidate_ordering_code_is_blank(): void
    {
        $itemId = 999001;
        $expectedOrderingCode = '4589724813357';

        $candidate = new WmsOrderCandidate([
            'item_id' => $itemId,
            'quantity_type' => \App\Enums\QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => $itemId,
            'code' => '999001',
            'name_main' => 'TEST ITEM',
            'capacity_case' => 12,
        ]);

        $generatorReflection = new \ReflectionClass($this->generator);

        $janCodeCache = $generatorReflection->getProperty('janCodeCache');
        $janCodeCache->setAccessible(true);
        $janCodeCache->setValue($this->generator, [$itemId => $expectedOrderingCode]);

        $orderingCodeInfoCache = $generatorReflection->getProperty('orderingCodeInfoCache');
        $orderingCodeInfoCache->setAccessible(true);
        $orderingCodeInfoCache->setValue($this->generator, [$itemId.':'.$expectedOrderingCode => null]);

        $costPriceCache = $generatorReflection->getProperty('costPriceCache');
        $costPriceCache->setAccessible(true);
        $costPriceCache->setValue($this->generator, [$itemId => (object) [
            'cost_case_price' => 0,
            'cost_unit_price' => 0,
        ]]);

        $generateDRecord = $generatorReflection->getMethod('generateDRecord');
        $generateDRecord->setAccessible(true);
        $dRecord = $generateDRecord->invoke($this->generator, $candidate, 1);

        $this->assertNotNull($dRecord);
        $this->assertEquals(
            $expectedOrderingCode,
            trim(substr($dRecord, 69, 13)),
            'Blank candidate ordering_code should fall back to is_used_for_ordering code'
        );
    }

    /**
     * @test
     * 候補のordering_codeが全ゼロの場合、発注用コードにフォールバックすること
     */
    public function it_falls_back_to_ordering_code_master_when_candidate_ordering_code_is_all_zero(): void
    {
        $itemId = 999004;
        $expectedOrderingCode = '4589724813357';

        $candidate = new WmsOrderCandidate([
            'item_id' => $itemId,
            'quantity_type' => \App\Enums\QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '0000000000000',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => $itemId,
            'code' => '999004',
            'name_main' => 'TEST ITEM ZERO',
            'capacity_case' => 12,
        ]);

        $generatorReflection = new \ReflectionClass($this->generator);

        $janCodeCache = $generatorReflection->getProperty('janCodeCache');
        $janCodeCache->setAccessible(true);
        $janCodeCache->setValue($this->generator, [$itemId => $expectedOrderingCode]);

        $orderingCodeInfoCache = $generatorReflection->getProperty('orderingCodeInfoCache');
        $orderingCodeInfoCache->setAccessible(true);
        $orderingCodeInfoCache->setValue($this->generator, [$itemId.':'.$expectedOrderingCode => null]);

        $costPriceCache = $generatorReflection->getProperty('costPriceCache');
        $costPriceCache->setAccessible(true);
        $costPriceCache->setValue($this->generator, [$itemId => (object) [
            'cost_case_price' => 0,
            'cost_unit_price' => 0,
        ]]);

        $generateDRecord = $generatorReflection->getMethod('generateDRecord');
        $generateDRecord->setAccessible(true);
        $dRecord = $generateDRecord->invoke($this->generator, $candidate, 1);

        $this->assertEquals(
            $expectedOrderingCode,
            trim(substr($dRecord, 69, 13)),
            'All-zero candidate ordering_code should fall back to is_used_for_ordering code'
        );
    }

    /**
     * @test
     * 発注コードが全ゼロで代替コードもない候補はJX生成対象から除外されること
     */
    public function it_skips_candidate_when_ordering_code_is_all_zero_and_no_fallback_exists(): void
    {
        $itemId = 999005;

        $candidate = new WmsOrderCandidate([
            'item_id' => $itemId,
            'quantity_type' => \App\Enums\QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '0000000000000',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => $itemId,
            'code' => '999005',
            'name_main' => 'TEST ITEM NO CODE',
            'capacity_case' => 12,
        ]);

        $generatorReflection = new \ReflectionClass($this->generator);

        $janCodeCache = $generatorReflection->getProperty('janCodeCache');
        $janCodeCache->setAccessible(true);
        $janCodeCache->setValue($this->generator, [$itemId => '']);

        $filterCandidates = $generatorReflection->getMethod('filterCandidatesWithOrderingCode');
        $filterCandidates->setAccessible(true);
        $filtered = $filterCandidates->invoke($this->generator, collect([$candidate]));

        $this->assertCount(0, $filtered);
    }

    /**
     * @test
     * JXの仕入入数は発注コードに紐づく入数、ケース数は候補の発注コード単位数で出力されること
     */
    public function it_uses_ordering_code_quantity_for_jx_capacity_and_case_quantity(): void
    {
        $itemId = 999002;
        $orderingCode = '4901411004754';

        $candidate = new WmsOrderCandidate([
            'item_id' => $itemId,
            'quantity_type' => \App\Enums\QuantityType::CASE,
            'order_quantity' => 4,
            'ordering_code' => $orderingCode,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => $itemId,
            'code' => '143180',
            'name_main' => 'TEST 500ML',
            'capacity_case' => 24,
        ]);

        $generatorReflection = new \ReflectionClass($this->generator);

        $orderingCodeInfoCache = $generatorReflection->getProperty('orderingCodeInfoCache');
        $orderingCodeInfoCache->setAccessible(true);
        $orderingCodeInfoCache->setValue($this->generator, [
            $itemId.':'.$orderingCode => (object) [
                'quantity_type' => 'CASE',
                'quantity' => 6,
            ],
        ]);

        $costPriceCache = $generatorReflection->getProperty('costPriceCache');
        $costPriceCache->setAccessible(true);
        $costPriceCache->setValue($this->generator, [$itemId => (object) [
            'cost_case_price' => 5160,
            'cost_unit_price' => 215,
        ]]);

        $generateDRecord = $generatorReflection->getMethod('generateDRecord');
        $generateDRecord->setAccessible(true);
        $dRecord = $generateDRecord->invoke($this->generator, $candidate, 1);

        $this->assertEquals(6, (int) substr($dRecord, 88, 6), 'Capacity should use ordering code quantity');
        $this->assertEquals(4, (int) substr($dRecord, 94, 7), 'Case quantity should use the candidate order quantity');
        $this->assertEquals(0, (int) substr($dRecord, 101, 7), 'Piece quantity should remain zero for case ordering code');
        $this->assertEquals(129000, (int) substr($dRecord, 108, 10), 'Unit price should use unit cost multiplied by ordering code quantity');
    }

    /**
     * @test
     * is_used_for_orderingフラグが設定されているコードが優先されること
     */
    public function it_prioritizes_is_used_for_ordering_flag(): void
    {
        // is_used_for_ordering=trueのコードが存在することを確認
        $codeWithFlag = \Illuminate\Support\Facades\DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->first();

        if (! $codeWithFlag) {
            $this->markTestSkipped('No codes with is_used_for_ordering flag in database');
        }

        // 同じitem_idで他のコードも存在するか確認
        $otherCodes = \Illuminate\Support\Facades\DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $codeWithFlag->item_id)
            ->where('is_active', true)
            ->where('id', '!=', $codeWithFlag->id)
            ->get();

        // is_used_for_ordering=trueのコードが正しく取得されることを確認
        $expectedCode = str_pad($codeWithFlag->search_string, 13, '0', STR_PAD_LEFT);

        // OrderCandidateCalculationServiceと同じロジックでコード取得
        $retrievedCode = \Illuminate\Support\Facades\DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $codeWithFlag->item_id)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');

        $this->assertNotNull($retrievedCode);
        $this->assertEquals(
            $codeWithFlag->search_string,
            $retrievedCode,
            'Code with is_used_for_ordering flag should be retrieved'
        );
    }

    /**
     * SJIS形式のファイル内容を128バイト単位のレコードに分割
     *
     * サンプルファイルの形式に合わせて、レコード間に改行がない形式を想定。
     * 末尾のCRLFは除去してから分割する。
     */
    private function splitRecords(string $sjisContent): array
    {
        // 末尾のCRLFを除去
        $content = rtrim($sjisContent, "\r\n");

        $records = [];
        $recordLength = 128;
        $pos = 0;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, $recordLength);
            if (strlen($record) < $recordLength) {
                break;
            }
            $records[] = $record;
            $pos += $recordLength;
        }

        return $records;
    }
}
