<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use Illuminate\Support\Facades\DB;

class PolicyCsvService
{
    public const HEADERS = ['商品CD', '方式', '発注先CD', '仕入先CD', '移動元倉庫CD', '配送コースCD', '発注点', '最大発注数', '最小発注数', '最小発注単位'];

    public function preview(int $clientId, int $warehouseId, string $content): array
    {
        throw_if(strlen($content) > 1048576, \InvalidArgumentException::class, 'CSVは1MB以下にしてください。');
        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win'], true);
        throw_unless($encoding, \InvalidArgumentException::class, 'CSVの文字コードを判定できません。');
        $content = preg_replace('/^\xEF\xBB\xBF/', '', mb_convert_encoding($content, 'UTF-8', $encoding));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            $header = fgetcsv($stream, escape: '');
            throw_unless($header === self::HEADERS, \InvalidArgumentException::class, 'CSVの見出しが一致しません。出力したCSVを使用してください。');
            $preview = [];
            $seen = [];
            $db = DB::connection('sakemaru');
            $routes = app(OrderRouteEligibility::class);
            $eligibleIds = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                throw_if(count($preview) >= 100 || count($row) !== count($header), \InvalidArgumentException::class, 'CSVは100行以内・所定の列数にしてください。');
                $data = array_combine($header, $row);
                $eligibleIds[$data['方式']] ??= $routes->contractorIds($data['方式']);
                throw_if(isset($seen[$data['商品CD']]), \InvalidArgumentException::class, '商品CDが重複しています。');
                $seen[$data['商品CD']] = true;
                $item = $db->table('items')->where(['client_id' => $clientId, 'code' => $data['商品CD'], 'is_active' => 1])->first(['id', 'code', 'name']);
                throw_unless($item, \InvalidArgumentException::class, '商品CDを確認してください: '.$data['商品CD']);
                $relation = $db->table('item_contractors as ic')->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
                    ->join('suppliers as s', 's.id', '=', 'ic.supplier_id')->join('partners as p', 'p.id', '=', 's.partner_id')
                    ->where('ic.client_id', $clientId)->where('ic.warehouse_id', $warehouseId)->where('ic.item_id', $item->id)
                    ->where('c.code', $data['発注先CD'])->where('p.code', $data['仕入先CD'])->get(['ic.contractor_id', 'ic.supplier_id']);
                throw_unless($relation->count() === 1, \InvalidArgumentException::class, '発注先・仕入先の関係を確認してください: '.$data['商品CD']);
                try {
                    $routes->assertEligible($data['方式'], (int) $relation->first()->contractor_id, $eligibleIds[$data['方式']]);
                } catch (\RuntimeException $exception) {
                    throw new \InvalidArgumentException('商品CD '.$data['商品CD'].'：'.$exception->getMessage(), 0, $exception);
                }
                $current = WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $warehouseId, 'item_id' => $item->id])->first();
                $values = [
                    'item_id' => $item->id, 'version' => $current?->version, 'channel' => $data['方式'],
                    'contractor_id' => $relation->first()->contractor_id, 'supplier_id' => $relation->first()->supplier_id,
                    'strategy_key' => $current?->strategy_key ?? 'threshold_v1',
                    'reorder_point_pieces' => $data['発注点'], 'max_order_pieces' => $data['最大発注数'],
                    'min_order_pieces' => $data['最小発注数'], 'order_multiple_pieces' => $data['最小発注単位'],
                    'target_stock_pieces' => null,
                ];
                if ($data['方式'] === 'LOGISTICS') {
                    $source = $db->table('warehouses')->where(['client_id' => $clientId, 'code' => $data['移動元倉庫CD'], 'is_active' => 1, 'is_virtual' => 0])->value('id');
                    $course = $db->table('delivery_courses')->where(['client_id' => $clientId, 'code' => $data['配送コースCD']])->value('id');
                    throw_unless($source && $course, \InvalidArgumentException::class, '移動元倉庫・配送コースを確認してください。');
                    $values += ['source_warehouse_id' => $source, 'delivery_course_id' => $course];
                }
                $values = app(PolicyService::class)->validate($values);
                $preview[] = ['item_code' => $item->code, 'item_name' => $item->name, 'before' => $current?->toArray(), 'after' => $values];
            }
            throw_if($preview === [], \InvalidArgumentException::class, 'CSVに明細がありません。');

            return $preview;
        } finally {
            fclose($stream);
        }
    }

    public function apply(int $clientId, int $warehouseId, array $preview, int $actorId): void
    {
        throw_if(count($preview) < 1 || count($preview) > 100, \InvalidArgumentException::class, '取込件数が不正です。');
        DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $preview, $actorId) {
            foreach ($preview as $row) {
                app(PolicyService::class)->save($clientId, $warehouseId, $row['after'], $actorId);
            }
        }, 3);
    }
}
