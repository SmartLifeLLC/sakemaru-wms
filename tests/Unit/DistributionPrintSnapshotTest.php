<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DistributionProductController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DistributionPrintSnapshotTest extends TestCase
{
    private $db;
    private DistributionProductController $controller;
    private PermissionService $permission;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../app/Http/Controllers/Api/DistributionProductController.php';
        $app = new class extends Container {
            public function abort($code, $message = '', array $headers = []) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
            }
        };
        Container::setInstance($app);
        $app->instance('config', new Repository(['app'=>['client_id'=>1], 'database'=>['default'=>'sakemaru']]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>''], 'sakemaru');
        $capsule->getDatabaseManager()->setDefaultConnection('sakemaru');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db=$capsule->getConnection('sakemaru');
        $app->instance('db',$capsule->getDatabaseManager());
        $app->instance('db.schema',$this->db->getSchemaBuilder());
        $app->instance('validator', new Factory(new Translator(new ArrayLoader,'ja'),$app));
        $app->instance('encrypter',new Encrypter(str_repeat('x',32),'AES-256-CBC'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $responses=$this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn($data,$status=200)=>new JsonResponse($data,$status));
        $responses->method('streamDownload')->willReturnCallback(fn($callback,$name,$headers=[])=>new StreamedResponse($callback,200,$headers));
        $app->instance(ResponseFactory::class,$responses);
        Request::macro('validate',function($rules){return \Illuminate\Support\Facades\Validator::make($this->all(),$rules)->validate();});
        $this->db->getPdo()->sqliteCreateFunction('JSON_UNQUOTE',fn($value)=>$value);
        $this->db->getPdo()->sqliteCreateFunction('JSON_LENGTH',fn($value)=>is_string($value)?count(json_decode($value,true)??[]):0);
        $this->db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code TEXT, name TEXT, is_active INTEGER)');
        $this->db->table('warehouses')->insert([['id'=>91,'client_id'=>1,'code'=>'91','name'=>'倉庫','is_active'=>1],['id'=>1,'client_id'=>1,'code'=>'01','name'=>'店舗,引用"符','is_active'=>1]]);
        $this->db->statement('CREATE TABLE wms_distribution_revisions (client_id INTEGER, mode TEXT, warehouse_id INTEGER, revision INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(client_id,mode,warehouse_id))');
        $this->db->statement('CREATE TABLE wms_distribution_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, mode TEXT, warehouse_id INTEGER, row_id TEXT, row_data TEXT, sort_order INTEGER, product_code TEXT, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('wms_distribution_revisions')->insert(['client_id'=>1,'mode'=>'direct','warehouse_id'=>91,'revision'=>5]);
        $this->row('a');
        $this->permission=$this->createMock(PermissionService::class);
        $this->permission->method('check')->willReturn(true);
        $this->user=new class extends \Illuminate\Foundation\Auth\User {
            public int $warehouse = 91;
            public function getSelectedWarehouseId() {return $this->warehouse;}
        };
        $this->user->id=7;
        $this->controller=new DistributionProductController;
    }
    private function row(string $id,array $data=[],int $client=1): void
    {
        $this->db->table('wms_distribution_rows')->insert(['client_id'=>$client,'mode'=>'direct','warehouse_id'=>91,'row_id'=>$id,'product_code'=>'100','sort_order'=>1,
            'row_data'=>json_encode(array_merge(['id'=>$id,'productCode'=>'100','name'=>'商品,"引用"','orderDate'=>'2026-10-25','supplier'=>'保存済仕入先','alloc_01'=>2,'wish_01'=>0,'poCase'=>0,'poEach'=>0],$data),JSON_UNESCAPED_UNICODE)]);
    }
    private function request(array $data): Request
    {
        $r=Request::create('/test','POST',$data);$r->setUserResolver(fn()=>$this->user);return $r;
    }
    private function prepare(): array
    {
        $r=$this->controller->markDirectRequestPrinted($this->request(['phase'=>'prepare','row_ids'=>['a']]),$this->permission);
        self::assertSame(200,$r->getStatusCode());return $r->getData(true)['result']['data'];
    }
    private function complete(string $token,array $ids=['a']): JsonResponse
    {
        return $this->controller->markDirectRequestPrinted($this->request(['phase'=>'complete','print_token'=>$token,'row_ids'=>$ids]),$this->permission);
    }
    public function test_prepare_is_read_only_and_completion_uses_exact_revision(): void
    {
        $s=$this->prepare();self::assertSame(5,$s['revision']);self::assertSame('a',$s['rows'][0]['id']);
        self::assertArrayNotHasKey('printed',json_decode($this->db->table('wms_distribution_rows')->value('row_data'),true));
        $r=$this->complete($s['print_token']);self::assertSame(200,$r->getStatusCode());self::assertSame(6,$r->getData(true)['result']['data']['revision']);
        self::assertEquals(true,json_decode($this->db->table('wms_distribution_rows')->value('row_data'),true)['printed']);
        self::assertSame(409,$this->complete($s['print_token'])->getStatusCode());
    }
    public function test_other_tab_update_rejects_stale_print(): void
    {
        $s=$this->prepare();$this->db->table('wms_distribution_revisions')->update(['revision'=>6]);
        self::assertSame(409,$this->complete($s['print_token'])->getStatusCode());
        self::assertArrayNotHasKey('printed',json_decode($this->db->table('wms_distribution_rows')->value('row_data'),true));
    }
    public function test_content_changed_without_revision_is_also_rejected(): void
    {
        $s=$this->prepare();
        $this->db->table('wms_distribution_rows')->update(['row_data'=>json_encode(['id'=>'a','alloc_01'=>99])]);
        self::assertSame(409,$this->complete($s['print_token'])->getStatusCode());
    }

    public function test_token_is_bound_to_user_rows_and_is_tamper_resistant(): void
    {
        $s=$this->prepare();self::assertSame(409,$this->complete($s['print_token'],['b'])->getStatusCode());
        self::assertSame(409,$this->complete($s['print_token'].'tamper')->getStatusCode());
        $this->user->id=8;self::assertSame(409,$this->complete($s['print_token'])->getStatusCode());
    }
    public function test_deleted_row_cannot_be_marked_printed(): void
    {
        $s=$this->prepare();$this->db->table('wms_distribution_rows')->update(['deleted_at'=>'2026-10-25']);
        self::assertSame(409,$this->complete($s['print_token'])->getStatusCode());
    }
    public function test_export_includes_all_450_matching_rows_dynamic_store_and_bom(): void
    {
        for($i=1;$i<450;$i++)$this->row('row-'.$i,['alloc_77'=>3]);
        $this->row('other-client',[],2);
        $r=$this->controller->exportDirectDistributionRows($this->request(['expected_revision'=>5]),$this->permission);
        ob_start();$r->sendContent();$csv=ob_get_clean();
        self::assertStringStartsWith("\xEF\xBB\xBF",$csv);
        $f=fopen('php://memory','r+');fwrite($f,substr($csv,3));rewind($f);
        $header=fgetcsv($f,0,',','"','');self::assertContains('店舗,引用"符_分配',$header);self::assertContains('店舗CD 77_分配',$header);
        $count=0;while(($row=fgetcsv($f,0,',','"',''))!==false){$count++;self::assertSame('商品,"引用"',$row[3]);if($count>1)self::assertSame('5',$row[10]);}
        self::assertSame(450,$count);fclose($f);
    }
    public function test_export_rejects_revision_conflict_and_empty_filter(): void
    {
        self::assertSame(409,$this->controller->exportDirectDistributionRows($this->request(['expected_revision'=>4]),$this->permission)->getStatusCode());
        self::assertSame(422,$this->controller->exportDirectDistributionRows($this->request(['expected_revision'=>5,'order_date_from'=>'2026-11-01']),$this->permission)->getStatusCode());
    }
    public function test_export_requires_distribution_permission(): void
    {
        $permission=$this->createMock(PermissionService::class);
        $permission->method('check')->willReturn(false);
        try {
            $this->controller->exportDirectDistributionRows($this->request(['expected_revision'=>5]),$permission);
            self::fail('Missing permission must reject export');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403,$e->getStatusCode());
        }
    }
    public function test_store_cannot_request_another_stores_actual_document(): void
    {
        $this->user->warehouse=1;
        $service=$this->createMock(\App\Services\Distribution\DistributionTransferResultService::class);
        $service->expects(self::never())->method('resolve');
        try {
            $this->controller->warehouseTransferResults($this->request(['context'=>'store','row_ids'=>['a'],'destination_keys'=>['02']]),$this->permission,$service);
            self::fail('Another destination must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403,$e->getStatusCode());
        }
    }
    public function test_result_endpoint_uses_saved_rows_and_intersects_requested_destinations(): void
    {
        $this->db->table('wms_distribution_rows')->update(['mode'=>'allocation']);
        $service=$this->createMock(\App\Services\Distribution\DistributionTransferResultService::class);
        $service->expects(self::once())->method('resolve')->with(1,91,self::callback(function($rows){
            return count($rows)===1 && $rows[0]['alloc_01']===2 && $rows[0]['_requested_destination_keys']===['01'];
        }),['01'])->willReturn(['documents'=>[],'queues'=>[]]);
        $r=$this->controller->warehouseTransferResults($this->request(['context'=>'edit','row_ids'=>['a'],
            'destination_keys'=>['01'],'row_destinations'=>['allocation::a'=>['01','02']]]),$this->permission,$service);
        self::assertSame(200,$r->getStatusCode());
    }

}
