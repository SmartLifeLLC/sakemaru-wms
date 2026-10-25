<?php

namespace Tests\Unit;

use App\Services\Distribution\DistributionStockTransferSlipService;
use App\Services\Distribution\DistributionTransferResultService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

// Deliberately uses an in-memory database. No application env or shared DB is loaded.
class DistributionTransferResultTest extends TestCase
{
    private Manager $db;
    private DistributionTransferResultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../app/Services/Distribution/DistributionTransferResultService.php';
        require_once __DIR__.'/../../app/Services/Distribution/DistributionStockTransferSlipService.php';
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['client_id' => 1]]));
        $this->db = new Manager($container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sakemaru');
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $container->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $db = $this->db->getConnection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code TEXT, name TEXT, stock_warehouse_id INTEGER)');
        $db->statement('CREATE TABLE stock_transfer_queue (id INTEGER, client_id INTEGER, action_type TEXT, from_warehouse_code TEXT, to_warehouse_code TEXT, items TEXT, note TEXT, status TEXT, is_success INTEGER, retry_count INTEGER, stock_transfer_id INTEGER)');
        $db->statement('CREATE TABLE stock_transfers (id INTEGER, client_id INTEGER, trade_id INTEGER, from_warehouse_id INTEGER, to_warehouse_id INTEGER, picking_date TEXT, delivered_date TEXT, is_active INTEGER)');
        $db->statement('CREATE TABLE trades (id INTEGER, client_id INTEGER, serial_id TEXT, trade_category TEXT, slip_number TEXT, is_latest INTEGER, is_active INTEGER, is_offset INTEGER, process_date TEXT)');
        $db->statement('CREATE TABLE trade_items (id INTEGER, client_id INTEGER, trade_id INTEGER, item_id INTEGER, item_name TEXT, capacity_case INTEGER, total_piece_quantity INTEGER, note TEXT, is_active INTEGER, order_of_items_in_slip INTEGER)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code TEXT)');
        $db->table('warehouses')->insert([
            ['id' => 91, 'client_id' => 1, 'code' => '91', 'name' => '出庫倉庫'],
            ['id' => 1, 'client_id' => 1, 'code' => '1', 'name' => '転入店舗'],
        ]);
        $this->service = new DistributionTransferResultService;
        $this->transfer(11, 21, 'SERIAL-1', null, [101, 102]);
        $this->queue(31, 11, ['a', 'b']);
    }

    private function table(string $table) { return $this->db->getConnection('sakemaru')->table($table); }
    private function queue(int $id, int $transfer, array $rows): void
    {
        $dedupe = new DistributionStockTransferSlipService;
        $this->table('stock_transfer_queue')->insert([
            'id' => $id, 'client_id' => 1, 'action_type' => 'CREATE', 'from_warehouse_code' => '91', 'to_warehouse_code' => '1',
            'items' => json_encode(array_map(fn ($row) => ['_distribution_dedupe_key' => $dedupe->makeDistributionLineDedupeKey(91, 1, $row)], $rows)),
            'status' => 'FINISHED', 'is_success' => 1, 'retry_count' => 0, 'stock_transfer_id' => $transfer,
        ]);
    }
    private function transfer(int $id, int $trade, string $serial, ?string $number, array $lines): void
    {
        $this->table('trades')->insert(['id'=>$trade,'client_id'=>1,'serial_id'=>$serial,'trade_category'=>'STOCK_TRANSFER','slip_number'=>$number,'is_latest'=>1,'is_active'=>1,'is_offset'=>0,'process_date'=>'2026-10-31']);
        $this->table('stock_transfers')->insert(['id'=>$id,'client_id'=>1,'trade_id'=>$trade,'from_warehouse_id'=>91,'to_warehouse_id'=>1,'picking_date'=>'2026-10-31','delivered_date'=>'2026-11-01','is_active'=>1]);
        foreach ($lines as $i => $line) {
            $this->table('items')->insert(['id'=>$line,'client_id'=>1,'code'=>(string)$line]);
            $this->table('trade_items')->insert(['id'=>$line,'client_id'=>1,'trade_id'=>$trade,'item_id'=>$line,'item_name'=>'商品'.$line,'capacity_case'=>12,'total_piece_quantity'=>$i+2,'note'=>'明細','is_active'=>1,'order_of_items_in_slip'=>$i]);
        }
    }
    private function resolve(array $extra = []): array
    {
        return $this->service->resolve(1, 91, [array_merge(['id'=>'a','alloc_01'=>2,'warehouseTransferQueueIds'=>[31]], $extra)], ['01']);
    }

    public function test_explicit_date_requires_column_null_is_compatible_and_retry_keeps_original_date(): void
    {
        $db=$this->db->getConnection('sakemaru');
        foreach (['request_id','slip_number','process_date','delivered_date','delivery_course_id','created_at','updated_at'] as $column) {
            $db->statement('ALTER TABLE stock_transfer_queue ADD COLUMN '.$column.' TEXT');
        }
        $method=new \ReflectionMethod(DistributionStockTransferSlipService::class,'upsertStockTransferQueue');
        $service=new DistributionStockTransferSlipService;
        $group=['request_id'=>'null-date','process_date'=>'2026-11-01','destination'=>(object)['code'=>'1'],'delivery_course_id'=>1,'items'=>[]];
        $result=$method->invoke($service,(object)['code'=>'91'],$group,7,null,null);
        self::assertTrue($result['created']);
        Container::getInstance()->instance('db.schema',$db->getSchemaBuilder());
        try {
            $method->invoke($service,(object)['code'=>'91'],array_replace($group,['request_id'=>'explicit']),7,null,'2026-10-31');
            self::fail('Missing date column must reject explicit date');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('出荷日を保存する準備',$e->getMessage());
        }
        $db->statement('ALTER TABLE stock_transfer_queue ADD COLUMN picking_date TEXT');
        $group['request_id']='explicit';
        $method->invoke($service,(object)['code'=>'91'],$group,7,null,'2026-10-31');
        self::assertSame('2026-10-31',$this->table('stock_transfer_queue')->where('request_id','explicit')->value('picking_date'));
        $method->invoke($service,(object)['code'=>'91'],$group,7,null,'2026-11-02');
        self::assertSame('2026-10-31',$this->table('stock_transfer_queue')->where('request_id','explicit')->value('picking_date'));
    }

    public function test_partial_selection_returns_whole_slip_and_nullable_number(): void
    {
        $result = $this->resolve();
        self::assertSame('succeeded', $result['queues'][0]['state']);
        self::assertSame([101,102], array_column($result['documents'][0]['rows'], 'tradeItemId'));
        self::assertSame('SERIAL-1', $result['documents'][0]['serial_id']);
        self::assertNull($result['documents'][0]['slip_number']);
    }
    public function test_each_reprint_reads_current_number_including_clear(): void
    {
        foreach (['NUMBER-1','NUMBER-2', null] as $number) {
            $this->table('trades')->where('id',21)->update(['slip_number'=>$number]);
            self::assertSame($number, $this->resolve()['documents'][0]['slip_number']);
        }
    }
    public function test_correction_follows_serial_to_latest_active_non_offset_trade(): void
    {
        $this->table('trades')->where('id',21)->update(['is_latest'=>0]);
        $this->transfer(12, 22, 'SERIAL-1', 'CORRECTED', [103]);
        $doc=$this->resolve()['documents'][0];
        self::assertSame(12,$doc['stock_transfer_id']);
        self::assertSame([103],array_column($doc['rows'],'tradeItemId'));
        self::assertSame('2026-10-31',$doc['picking_date']);
    }
    public function test_multiple_queues_to_same_store_are_separate_documents(): void
    {
        $this->transfer(12,22,'SERIAL-2','OTHER',[103]);
        $this->queue(32,12,['c']);
        $result=$this->service->resolve(1,91,[['id'=>'a','alloc_01'=>2,'warehouseTransferQueueIds'=>[31,32]],['id'=>'c','alloc_01'=>5,'warehouseTransferQueueIds'=>[31,32]]],['01']);
        self::assertSame([11,12],array_column($result['documents'],'stock_transfer_id'));
    }
    public function test_reference_has_no_invented_identifier(): void
    {
        $result=$this->resolve(['id'=>'wish','alloc_01'=>0,'wish_01'=>4,'warehouseTransferQueueIds'=>[]]);
        self::assertSame([], $result['queues']);
        self::assertSame('reference', $result['documents'][0]['kind']);
        self::assertNull($result['documents'][0]['serial_id']);
        self::assertNull($result['documents'][0]['slip_number']);
    }
    public function test_positive_quantity_without_matching_queue_is_not_a_reference(): void
    {
        $result=$this->resolve(['id'=>'not-matched']);
        self::assertSame('missing',$result['queues'][0]['state']);
        self::assertSame([],$result['documents']);
    }
    public function test_pending_retry_failed_do_not_yield_documents(): void
    {
        foreach ([['PROCESSING',0,0,'pending'],['BEFORE',0,1,'retrying'],['FINISHED',0,3,'failed']] as [$status,$success,$retry,$expected]) {
            $this->table('stock_transfer_queue')->where('id',31)->update(['status'=>$status,'is_success'=>$success,'retry_count'=>$retry]);
            $result=$this->resolve();
            self::assertSame($expected,$result['queues'][0]['state']);
            self::assertSame([],$result['documents']);
        }
    }
    public function test_selected_destination_pairs_do_not_include_other_slips(): void
    {
        $result=$this->resolve(['_requested_destination_keys'=>[]]);
        self::assertSame([], $result['documents']);
        self::assertSame([], $result['queues']);
    }
    public function test_direct_store_rows_without_transfer_are_explicit_references(): void
    {
        $result=$this->resolve(['id'=>'direct','_distribution_mode'=>'direct','warehouseTransferQueueIds'=>[]]);
        self::assertSame('direct-reference',$result['documents'][0]['kind']);
        self::assertNull($result['documents'][0]['serial_id']);
    }
    public function test_missing_item_does_not_silently_drop_part_of_a_slip(): void
    {
        $this->table('items')->where('id',102)->delete();
        $this->expectException(\RuntimeException::class);
        $this->resolve();
    }

    public function test_missing_actual_document_is_an_error_not_a_blank_number(): void
    {
        $this->table('stock_transfer_queue')->where('id',31)->update(['stock_transfer_id'=>999]);
        $this->expectException(\RuntimeException::class);
        $this->resolve();
    }
    public function test_cross_client_queue_cannot_be_used(): void
    {
        $this->table('stock_transfer_queue')->where('id',31)->update(['client_id'=>2]);
        self::assertSame('missing',$this->resolve()['queues'][0]['state']);
    }
    public function test_corrected_destination_cannot_leak_to_original_store(): void
    {
        $this->table('stock_transfers')->where('id',11)->update(['to_warehouse_id'=>2]);
        $this->expectException(\RuntimeException::class);
        $this->resolve();
    }
    public function test_one_hundred_and_one_lines_are_preserved_in_whole_slip(): void
    {
        $this->transfer(12,22,'LARGE','N101',range(1001,1101));
        $this->queue(32,12,['large']);
        $result=$this->resolve(['id'=>'large','warehouseTransferQueueIds'=>[32]]);
        self::assertCount(101,$result['documents'][0]['rows']);
    }
}
