<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MasterDataController extends ApiController
{
    /**
     * GET /api/master/warehouses
     *
     * 倉庫マスタ一覧取得
     *
     * @OA\Get(
     *     path="/api/master/warehouses",
     *     tags={"Master Data"},
     *     summary="Get warehouse master list",
     *     description="Retrieve all warehouses with id, code, name, kana_name, and out_of_stock_option",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=991),
     *                         @OA\Property(property="code", type="string", example="991"),
     *                         @OA\Property(property="name", type="string", example="酒丸本社"),
     *                         @OA\Property(property="kana_name", type="string", example="サケマルホンシャ"),
     *                         @OA\Property(property="out_of_stock_option", type="string", example="enum ('IGNORE_STOCK', 'UP_TO_STOCK')")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=false),
     *             @OA\Property(property="code", type="string", example="UNAUTHORIZED"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Unauthenticated")
     *             )
     *         )
     *     )
     * )
     */
    public function warehouses(): JsonResponse
    {
        $warehouses = DB::connection('sakemaru')
            ->table('warehouses')
            ->select([
                'id',
                'code',
                'name',
                'kana_name',
                'out_of_stock_option',
            ])
            ->orderBy('code', 'asc')
            ->get()
            ->toArray();

        return $this->success($warehouses);
    }
}
