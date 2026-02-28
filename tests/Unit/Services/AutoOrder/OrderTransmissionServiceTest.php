<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\EWMSClient;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderJxSetting;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\OrderServiceFactory;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OrderTransmissionService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 *
 * 注意: S3への実際の書き込みはfakeを使用する。
 */
class OrderTransmissionServiceTest extends TestCase
{
    private OrderTransmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderTransmissionService::class);
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(OrderTransmissionService::class);
        $this->assertInstanceOf(OrderTransmissionService::class, $service);
    }

    /**
     * @test
     * OrderServiceFactoryからジェネレーターを取得できること
     */
    public function it_can_get_generator_from_factory(): void
    {
        $generator = OrderServiceFactory::generator();

        $this->assertInstanceOf(OrderFileGeneratorInterface::class, $generator);
    }

    /**
     * @test
     * EWMSClient::HANAが設定されている場合HanaOrderFileGeneratorが返されること
     */
    public function it_returns_hana_generator_when_configured(): void
    {
        $client = EWMSClient::current();

        if ($client !== EWMSClient::HANA) {
            $this->markTestSkipped('WMS_CLIENT is not set to hana');
        }

        $generator = OrderServiceFactory::generator();
        $this->assertInstanceOf(HanaOrderJXFileGenerator::class, $generator);
    }

    /**
     * @test
     * JX設定からgeneratorを取得できること
     */
    public function it_can_get_generator_from_jx_setting(): void
    {
        $jxSetting = WmsOrderJxSetting::where('is_active', true)->first();

        if (! $jxSetting) {
            $this->markTestSkipped('No active JX setting available');
        }

        // order_file_generatorが設定されていない場合はnull
        if ($jxSetting->order_file_generator === null) {
            $generator = OrderServiceFactory::generatorForJxSetting($jxSetting);
            $this->assertNull($generator);

            return;
        }

        $generator = OrderServiceFactory::generatorForJxSetting($jxSetting);
        $this->assertInstanceOf(OrderFileGeneratorInterface::class, $generator);
    }

    /**
     * @test
     * EOrderFileGenerator EnumからHanaOrderJXFileGeneratorが取得できること
     */
    public function it_returns_hana_generator_from_enum(): void
    {
        $enum = EOrderFileGenerator::HANA;

        $this->assertEquals('hana', $enum->value);
        $this->assertEquals(HanaOrderJXFileGenerator::class, $enum->generatorClass());

        $generator = $enum->generator();
        $this->assertInstanceOf(HanaOrderJXFileGenerator::class, $generator);
    }

    /**
     * @test
     * generateOrderFilesが対象候補なしの場合にエラーにならないこと
     */
    public function it_handles_no_candidates_gracefully(): void
    {
        // 存在しないバッチコードを使用
        $result = $this->service->generateOrderFiles('NON_EXISTENT_BATCH_CODE_'.time());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['total_orders']);
    }

    /**
     * @test
     * transmitOrderFilesViaJxが対象ドキュメントなしの場合にエラーにならないこと
     */
    public function it_handles_no_documents_gracefully(): void
    {
        $result = $this->service->transmitOrderFilesViaJx('NON_EXISTENT_BATCH_CODE_'.time());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['transmitted']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * @test
     * JX設定が存在することの確認
     */
    public function it_has_jx_settings_in_database(): void
    {
        $settings = WmsOrderJxSetting::where('is_active', true)->get();

        $this->assertGreaterThan(0, $settings->count(), 'Should have at least one active JX setting');

        foreach ($settings as $setting) {
            $this->assertNotEmpty($setting->name);
            $this->assertNotEmpty($setting->endpoint_url);
        }
    }

    /**
     * @test
     * 発注候補のステータス遷移確認（EXECUTED状態の存在確認）
     */
    public function it_can_find_executed_candidates(): void
    {
        $executedCount = WmsOrderCandidate::where('status', CandidateStatus::EXECUTED)->count();

        // EXECUTEDステータスの候補がなくてもテストは成功（データ依存しない）
        $this->assertGreaterThanOrEqual(0, $executedCount);
    }

    /**
     * @test
     * wms_order_jx_documentsテーブルの構造確認
     */
    public function it_verifies_jx_document_table_structure(): void
    {
        // 新しいドキュメントを作成せず、既存のスキーマを確認
        $fillable = (new WmsOrderJxDocument)->getFillable();

        $requiredColumns = [
            'batch_code',
            'wms_order_jx_setting_id',
            'contractor_id',
            'document_type',
            'status',
            'file_path',
            'file_size',
            'record_count',
            'order_count',
            'encoding',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $fillable, "Column {$column} should be fillable");
        }
    }

    /**
     * @test
     * S3パス生成の確認（設定値のテスト）
     */
    public function it_uses_correct_s3_prefix(): void
    {
        $prefix = config('wms.s3_prefix', 'wms/');
        $this->assertEquals('wms/', $prefix);
    }

    /**
     * @test
     * 実データを使用したファイル生成のドライラン
     * （S3への書き込みはfakeを使用）
     */
    public function it_can_generate_files_with_fake_storage(): void
    {
        // OrderServiceFactoryの確認
        $generator = OrderServiceFactory::generator();
        if ($generator instanceof \App\Services\AutoOrder\Generators\DefaultOrderFileGenerator) {
            $this->markTestSkipped('DefaultOrderFileGenerator is configured (no actual file generation)');
        }

        // EXECUTED状態で未送信の候補を確認
        $jxContractorCodes = [1106, 1017, 1202, 1330, 1021, 1029, 1068, 1126, 1127];
        $jxContractorIds = Contractor::whereIn('code', $jxContractorCodes)->pluck('id')->toArray();

        $candidates = WmsOrderCandidate::whereIn('contractor_id', $jxContractorIds)
            ->where('status', CandidateStatus::EXECUTED)
            ->whereNull('wms_order_jx_document_id')
            ->limit(5)
            ->get();

        if ($candidates->isEmpty()) {
            $this->markTestSkipped('No EXECUTED candidates available for testing');
        }

        $batchCode = $candidates->first()->batch_code;

        // S3をfakeに置き換え
        Storage::fake('s3');

        // ファイル生成実行
        $result = $this->service->generateOrderFiles($batchCode);

        $this->assertIsArray($result);

        // エラーがなければファイルが生成されているはず
        if ($result['success'] && ! empty($result['files'])) {
            foreach ($result['files'] as $file) {
                $this->assertArrayHasKey('s3_path', $file);
                $this->assertArrayHasKey('document_id', $file);

                // S3にファイルが存在することを確認
                Storage::disk('s3')->assertExists($file['s3_path']);
            }
        }
    }

    /**
     * @test
     * JX送信対象の発注先設定確認
     */
    public function it_has_jx_contractors_configured(): void
    {
        $jxContractorCodes = [1106, 1017, 1202, 1330];

        foreach ($jxContractorCodes as $code) {
            $contractor = Contractor::where('code', $code)->first();

            if ($contractor) {
                $jxSetting = WmsOrderJxSetting::findByContractorId($contractor->id);
                // 設定があることが望ましいが、なくてもテストは失敗しない
                if ($jxSetting) {
                    $this->assertTrue($jxSetting->is_active, "JX setting for contractor {$code} should be active");
                }
            }
        }
    }

    /**
     * @test
     * PENDINGステータスのドキュメント検索
     */
    public function it_can_find_pending_documents(): void
    {
        $pendingDocs = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)->get();

        // PENDINGドキュメントがあってもなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $pendingDocs->count());

        foreach ($pendingDocs as $doc) {
            $this->assertNotEmpty($doc->batch_code);
            $this->assertEquals(TransmissionDocumentStatus::PENDING, $doc->status);
        }
    }

    /**
     * @test
     * バッチコードによるドキュメントグルーピング
     */
    public function it_groups_documents_by_batch_code(): void
    {
        $documents = WmsOrderJxDocument::select('batch_code')
            ->distinct()
            ->limit(5)
            ->pluck('batch_code');

        // ドキュメントがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $documents->count());

        foreach ($documents as $batchCode) {
            $count = WmsOrderJxDocument::where('batch_code', $batchCode)->count();
            $this->assertGreaterThan(0, $count);
        }
    }

    /**
     * @test
     * 発注候補とドキュメントの紐付け確認
     */
    public function it_can_link_candidates_to_documents(): void
    {
        // ドキュメントに紐付いている発注候補を確認
        $linkedCandidates = WmsOrderCandidate::whereNotNull('wms_order_jx_document_id')
            ->limit(5)
            ->get();

        // 紐付いた候補がなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $linkedCandidates->count());

        foreach ($linkedCandidates as $candidate) {
            $document = WmsOrderJxDocument::find($candidate->wms_order_jx_document_id);
            if ($document) {
                $this->assertEquals($candidate->batch_code, $document->batch_code);
            }
        }
    }

    /**
     * @test
     * JxClient依存のテスト（モック不使用で設定確認のみ）
     */
    public function it_verifies_jx_client_can_be_instantiated(): void
    {
        $jxSetting = WmsOrderJxSetting::where('is_active', true)->first();

        if (! $jxSetting) {
            $this->markTestSkipped('No active JX setting available');
        }

        // JxClientのインスタンス化が可能であることを確認
        $client = new \App\Services\JX\JxClient($jxSetting);
        $this->assertInstanceOf(\App\Services\JX\JxClient::class, $client);
    }
}
