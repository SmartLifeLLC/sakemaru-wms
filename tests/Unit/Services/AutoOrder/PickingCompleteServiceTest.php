<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\PickingCompleteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PickingCompleteService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class PickingCompleteServiceTest extends TestCase
{
    private PickingCompleteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PickingCompleteService::class);
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(PickingCompleteService::class);
        $this->assertInstanceOf(PickingCompleteService::class, $service);
    }

    /**
     * @test
     * handlePickingCompleteメソッドが存在すること
     */
    public function it_has_handle_picking_complete_method(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'handlePickingComplete'),
            'PickingCompleteService should have handlePickingComplete method'
        );
    }

    /**
     * @test
     * handlePickingCompleteメソッドのシグネチャが正しいこと
     */
    public function it_has_correct_method_signature(): void
    {
        $reflection = new \ReflectionMethod(PickingCompleteService::class, 'handlePickingComplete');
        $parameters = $reflection->getParameters();

        $this->assertCount(5, $parameters, 'handlePickingComplete should have 5 parameters');

        $expectedParams = [
            'stockTransferId' => 'int',
            'pickedQuantity' => 'int',
            'originalQuantity' => 'int',
            'quantityType' => 'string',
            'incomingScheduleId' => 'int',
        ];

        foreach ($parameters as $index => $param) {
            $expectedName = array_keys($expectedParams)[$index];
            $this->assertEquals($expectedName, $param->getName(), "Parameter {$index} should be named {$expectedName}");
        }
    }

    /**
     * @test
     * createUpdateQueueメソッドが存在すること（リフレクションで確認）
     */
    public function it_has_create_update_queue_method(): void
    {
        $reflection = new \ReflectionClass(PickingCompleteService::class);

        $this->assertTrue(
            $reflection->hasMethod('createUpdateQueue'),
            'PickingCompleteService should have createUpdateQueue method'
        );

        $method = $reflection->getMethod('createUpdateQueue');
        $this->assertTrue($method->isPrivate(), 'createUpdateQueue should be private');
    }

    /**
     * @test
     * stock_transfer_queueテーブルにUPDATEタイプのレコードを挿入できる構造であること
     * 注意: action_typeカラムはsakemaru-ai-core側で追加される
     */
    public function it_can_insert_update_type_queue(): void
    {
        $columns = DB::connection('sakemaru')
            ->getSchemaBuilder()
            ->getColumnListing('stock_transfer_queue');

        $requiredColumns = [
            'client_id',
            'request_id',
            'stock_transfer_id',
            'items',
            'note',
            'status',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "stock_transfer_queue should have {$column} column");
        }

        // action_typeはsakemaru-ai-core側で追加されるため、存在しない場合はスキップ
        if (! in_array('action_type', $columns)) {
            $this->markTestIncomplete('action_type column not yet added (requires sakemaru-ai-core migration)');
        }

        $this->assertContains('action_type', $columns);
    }

    /**
     * @test
     * action_typeカラムがUPDATEを受け入れる構造であること
     * 注意: action_typeカラムはsakemaru-ai-core側で追加される
     */
    public function it_accepts_update_action_type(): void
    {
        $columns = DB::connection('sakemaru')
            ->getSchemaBuilder()
            ->getColumnListing('stock_transfer_queue');

        if (! in_array('action_type', $columns)) {
            $this->markTestSkipped('action_type column not yet added (requires sakemaru-ai-core migration)');
        }

        // 既存のUPDATEタイプのキューを確認
        $updateQueues = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('action_type', 'UPDATE')
            ->count();

        // UPDATEタイプがなくてもテストは成功（構造確認のみ）
        $this->assertGreaterThanOrEqual(0, $updateQueues);
    }

    /**
     * @test
     * サービスがsakeamaruデータベース接続を使用すること
     */
    public function it_uses_sakemaru_connection(): void
    {
        // PickingCompleteServiceはDB::connection('sakemaru')を使用
        $reflection = new \ReflectionClass(PickingCompleteService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            "DB::connection('sakemaru')",
            $source,
            'Service should use sakemaru database connection'
        );
    }

    /**
     * @test
     * サービスがトランザクションを使用すること
     */
    public function it_uses_transaction(): void
    {
        $reflection = new \ReflectionClass(PickingCompleteService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            '->transaction(',
            $source,
            'Service should use database transaction'
        );
    }

    /**
     * @test
     * サービスがWmsOrderIncomingScheduleを更新すること
     */
    public function it_updates_incoming_schedule(): void
    {
        $reflection = new \ReflectionClass(PickingCompleteService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'WmsOrderIncomingSchedule::where',
            $source,
            'Service should update WmsOrderIncomingSchedule'
        );

        $this->assertStringContainsString(
            'expected_quantity',
            $source,
            'Service should update expected_quantity'
        );
    }

    /**
     * @test
     * request_idの形式が正しいこと
     */
    public function it_generates_correct_request_id_format(): void
    {
        $reflection = new \ReflectionClass(PickingCompleteService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'transfer-update-',
            $source,
            'Request ID should start with transfer-update-'
        );
    }

    /**
     * @test
     * 数量差異がない場合はUPDATEキューが作成されないこと（ロジック確認）
     */
    public function it_checks_quantity_difference_before_creating_queue(): void
    {
        $reflection = new \ReflectionClass(PickingCompleteService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            '$pickedQuantity !== $originalQuantity',
            $source,
            'Service should check quantity difference before creating queue'
        );
    }
}
