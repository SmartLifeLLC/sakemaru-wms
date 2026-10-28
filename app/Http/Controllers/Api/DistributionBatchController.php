<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsDistributionBatch;
use App\Models\WmsDistributionRow;
use App\Services\Distribution\DistributionBatchService;
use App\Services\Distribution\DistributionExcelReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sakemaru\Auth\Services\PermissionService;

class DistributionBatchController extends ApiController
{
    public function index(Request $request, PermissionService $permissions)
    {
        $this->authorizeUser($request, $permissions);
        $query = WmsDistributionBatch::query()->where('client_id', (int) config('app.client_id'))
            ->where('warehouse_id', (int) $request->user()->getSelectedWarehouseId())->with(['creator', 'updater']);
        $request->validate(['responsible_department' => ['nullable', Rule::in([...array_keys(WmsDistributionBatch::RESPONSIBLE_DEPARTMENTS), 'unassigned'])]], [], ['responsible_department' => '担当部署']);
        if ($request->input('responsible_department') === 'unassigned') {
            $query->whereNull('responsible_department');
        } elseif ($request->filled('responsible_department')) {
            $query->where('responsible_department', $request->input('responsible_department'));
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.mb_substr($request->string('search'), 0, 100).'%')
                ->orWhere('note', 'like', '%'.mb_substr($request->string('search'), 0, 100).'%'));
        }
        if ($request->boolean('mine')) {
            $query->where('created_by', $request->user()->id);
        }
        if ($request->filled('shipping_date')) {
            $request->validate(['shipping_date' => 'date_format:Y-m-d']);
            $query->whereDate('shipping_date', $request->input('shipping_date'));
        }
        if ($request->input('status') === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif (in_array($request->input('status'), ['pending', 'generated'], true)) {
            $query->whereNull('archived_at');
            $condition = fn ($q) => $q->whereRaw("JSON_EXTRACT(row_data, '$.warehouseTransferGenerated') = true")
                ->orWhereRaw("COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.warehouseTransferQueueIds')),0) > 0");
            if ($request->input('status') === 'pending') {
                $query->whereDoesntHave('rows', $condition);
            } else {
                $query->whereHas('rows', $condition);
            }
        }
        $batches = $query->orderByDesc('id')->paginate(50);
        // Load one batch at a time; eager-loading 50 full 6,000-row sheets can exhaust memory.
        $batches->getCollection()->transform(fn ($batch) => $this->describe($batch));

        return $this->success($batches->toArray());
    }

    public function store(Request $request, PermissionService $permissions)
    {
        $this->authorizeUser($request, $permissions);
        $data = $request->validate(['name' => 'required|string|max:100', 'shipping_date' => 'required|date_format:Y-m-d',
            'responsible_department' => ['required', Rule::in(array_keys(WmsDistributionBatch::RESPONSIBLE_DEPARTMENTS))],
            'note' => 'nullable|string|max:1000', 'execution_id' => 'required|uuid'], [],
            ['name' => '分配名', 'shipping_date' => '出荷日', 'note' => '備考', 'responsible_department' => '担当部署']);
        $key = 'create:'.(int) config('app.client_id').':'.(int) $request->user()->getSelectedWarehouseId().':'.$request->user()->id.':'.$data['execution_id'];
        $batch = WmsDistributionBatch::withTrashed()->firstOrCreate(['registration_key' => $key], [
            'client_id' => (int) config('app.client_id'), 'warehouse_id' => (int) $request->user()->getSelectedWarehouseId(),
            'name' => trim($data['name']), 'shipping_date' => $data['shipping_date'], 'note' => $data['note'] ?? '',
            'responsible_department' => $data['responsible_department'],
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);

        abort_if($batch->trashed(), 409, '登録済みの分配は削除されています。新規登録をやり直してください。');

        return $this->success($this->describe($batch));
    }

    public function show()
    {
        return $this->success($this->describe(DistributionBatchService::current()));
    }

    public function update(Request $request, DistributionBatchService $service)
    {
        $batch = DistributionBatchService::current();
        $data = $request->validate(['name' => 'required|string|max:100', 'shipping_date' => 'nullable|required_unless:archive,true|date_format:Y-m-d',
            'responsible_department' => ['sometimes', 'nullable', Rule::in(array_keys(WmsDistributionBatch::RESPONSIBLE_DEPARTMENTS))],
            'note' => 'nullable|string|max:1000', 'expected_revision' => 'required|integer|min:0', 'archive' => 'sometimes|boolean'], [],
            ['name' => '分配名', 'shipping_date' => '出荷日', 'note' => '備考', 'responsible_department' => '担当部署']);
        if ((int) $data['expected_revision'] !== $batch->revision) {
            return $this->error('この分配は別の画面で更新されています。再読み込みしてください。', 409, 'DISTRIBUTION_REVISION_CONFLICT');
        }
        $data['shipping_date'] ??= $batch->shipping_date?->format('Y-m-d');
        $dateChanged = $data['shipping_date'] !== $batch->shipping_date?->format('Y-m-d');
        if ($dateChanged && $service->hasGeneratedRows($batch) && ! ($batch->is_legacy && ! $batch->shipping_date)) {
            throw ValidationException::withMessages(['shipping_date' => '移動生成受付後は出荷日を変更できません。']);
        }
        $batch->update(['name' => trim($data['name']), 'shipping_date' => $data['shipping_date'], 'note' => $data['note'] ?? '',
            'responsible_department' => $data['responsible_department'] ?? $batch->responsible_department,
            'updated_by' => $request->user()->id, 'revision' => $batch->revision + 1,
            'archived_at' => $request->boolean('archive') ? now() : null]);

        return $this->success($this->describe($batch));
    }

    public function destroy(Request $request, DistributionBatchService $service)
    {
        $request->validate(['expected_revision' => 'required|integer|min:0']);
        $batch = DistributionBatchService::current();
        if ((int) $request->input('expected_revision') !== $batch->revision) {
            return $this->error('この分配は更新されています。再読み込みしてください。', 409, 'DISTRIBUTION_REVISION_CONFLICT');
        }
        if ($service->hasGeneratedRows($batch)) {
            throw ValidationException::withMessages(['分配' => '生成受付済みの明細がある分配は削除できません。']);
        }
        $batch->rows()->delete();
        $batch->delete();

        return $this->success(['deleted' => true]);
    }

    public function preview(Request $request, DistributionExcelReader $reader)
    {
        $request->validate(['file' => 'required|file|max:20480', 'quantity_unit' => 'required|in:case,piece',
            'store_mode' => 'required|in:name,code', 'mapping' => 'nullable|json']);
        $batch = DistributionBatchService::current();
        $file = $request->file('file');
        abort_unless(in_array(strtolower($file->getClientOriginalExtension()), ['xls', 'xlsx'], true), 422, 'xlsまたはxlsxファイルを選択してください。');
        $settings = ['quantity_unit' => $request->input('quantity_unit'), 'store_mode' => $request->input('store_mode'),
            'mapping' => json_decode($request->input('mapping', '{}'), true, 512, JSON_THROW_ON_ERROR)];
        if (! is_array($settings['mapping'])) {
            throw ValidationException::withMessages(['mapping' => '店舗の対応を再確認してください。']);
        }
        $items = DB::connection('sakemaru')->table('items')->where('client_id', $batch->client_id)
            ->get(['id', 'code', 'name', 'capacity_case', 'is_active'])->keyBy('code')->map(fn ($row) => (array) $row)->all();
        $destinations = DB::connection('sakemaru')->table('warehouses')->where('client_id', $batch->client_id)->where('is_active', true)
            ->get(['id', 'code', 'name'])->map(function ($d) use ($batch) {
                $d->allowed = in_array((int) $d->code, [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22], true) && (int) $d->id !== (int) $batch->warehouse_id;

                return (array) $d;
            })->all();
        try {
            $preview = $reader->parse($file->getRealPath(), $items, $destinations, $settings);
        } catch (\Throwable $e) {
            report($e);

            return $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Excelを読み込めません。破損や暗号化を確認してください。', 422);
        }
        $existing = $batch->rows()->get()->groupBy('item_id');
        $preview['new_count'] = $preview['update_count'] = $preview['unchanged_count'] = $preview['after_total'] = 0;
        foreach ($preview['rows'] as &$row) {
            $matches = $existing->get($row['item_id'], collect());
            if ($matches->count() > 1) {
                $preview['errors'][] = "商品CD{$row['product_code']}は既存明細に複数あります。明細を整理してください。";
            }
            $match = $matches->first();
            if ($match && DistributionBatchService::rowLocked($match->row_data)) {
                $preview['errors'][] = "商品CD{$row['product_code']}は生成受付済みのため上書きできません。";
            }
            $row['row_id'] = $match?->row_id;
            $row['before'] = $match?->row_data ?? [];
            $row['after'] = $row['before'];
            foreach ($row['allocations'] as $key => $quantity) {
                $row['after']['alloc_'.$key] = $quantity;
            }
            $row['after_total'] = array_sum(array_filter($row['after'], fn ($key) => str_starts_with((string) $key, 'alloc_'), ARRAY_FILTER_USE_KEY));
            if ($row['after_total'] > 99999999) {
                $preview['errors'][] = "商品CD{$row['product_code']}の更新後合計が99,999,999を超えます。";
            }
            $row['action'] = ! $match ? '新規' : ($row['before'] === $row['after'] ? '変更なし' : '更新');
            $preview[match ($row['action']) {
                '新規' => 'new_count', '変更なし' => 'unchanged_count', default => 'update_count'
            }]++;
            $preview['after_total'] += $row['after_total'];
        }
        unset($row);
        if ($batch->rows()->count() + $preview['new_count'] > 6000) {
            $preview['errors'][] = '取込後の商品行数が6,000行を超えます。';
        }
        $token = Str::random(64);
        $preview += ['token' => $token, 'revision' => $batch->revision, 'batch_id' => $batch->id,
            'user_id' => $request->user()->id, 'expires_at' => time() + 1800,
            'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'file_hash' => hash_file('sha256', $file->getRealPath()),
            'settings' => $settings, 'execution_id' => (string) Str::uuid()];
        Storage::disk('local')->put('distribution/previews/'.$token.'.json', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $preview['destinations'] = array_values(array_filter($destinations, fn ($d) => $d['allowed']));

        return $this->success($preview);
    }

    public function commit(Request $request)
    {
        $request->validate(['token' => 'required|string|size:64|alpha_num', 'acknowledge_warnings' => 'required|boolean']);
        $batch = DistributionBatchService::current();
        $path = 'distribution/previews/'.$request->input('token').'.json';
        abort_unless(Storage::disk('local')->exists($path), 422, 'プレビューが失効しました。再解析してください。');
        $preview = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        abort_unless($preview['batch_id'] === $batch->id && (int) $preview['user_id'] === (int) $request->user()->id, 403);
        $db = DB::connection('sakemaru');
        $previous = $db->table('wms_distribution_imports')->where('execution_id', $preview['execution_id'])->first();
        if ($previous) {
            return $this->success(json_decode($previous->summary, true));
        }
        abort_unless($preview['expires_at'] >= time() && $preview['revision'] === $batch->revision, 409, '分配またはプレビューが更新されています。再解析してください。');
        abort_if($preview['errors'] !== [] || $preview['rows'] === [], 422, '取込エラーを解消してください。');
        abort_if($preview['warnings'] !== [] && ! $request->boolean('acknowledge_warnings'), 422, '注意事項を確認してください。');
        $nextOrder = (int) ($batch->rows()->max('sort_order') ?? -1);
        $changes = [];
        foreach ($preview['rows'] as $import) {
            $master = $db->table('items')->where('client_id', $batch->client_id)->where('id', $import['item_id'])->first();
            abort_unless($master && $master->is_active && (int) $master->capacity_case === $import['units_per_case'], 409, '商品マスタが変更されています。再解析してください。');
            $row = $import['row_id'] ? $batch->rows()->where('row_id', $import['row_id'])->firstOrFail() : null;
            abort_if($row && DistributionBatchService::rowLocked($row->row_data), 409, '生成受付済みの明細があります。再解析してください。');
            if ($import['action'] === '変更なし') {
                continue;
            }
            $id = $row?->row_id ?? (string) Str::uuid();
            $data = $row?->row_data ?? ['id' => $id, 'source' => 'excel_import', 'sourceKey' => $preview['execution_id'].':'.$import['excel_row'],
                'checked' => false, 'poCase' => '', 'poEach' => '', 'orderDate' => now()->toDateString(), 'memo' => ''];
            $key = $row?->business_key ?? sha1($batch->id.':'.$id);
            foreach ($import['allocations'] as $destination => $quantity) {
                $data['alloc_'.$destination] = $quantity;
            }
            $data['visibleDestinationKeys'] = array_values(array_unique(array_merge(
                $data['visibleDestinationKeys'] ?? [], array_map('strval', array_keys($import['allocations']))
            )));
            $data['enteredDestinationKeys'] = array_values(array_unique(array_merge(
                $data['enteredDestinationKeys'] ?? [], array_map('strval', array_keys($import['allocations']))
            )));
            $data = array_replace($data, ['name' => $master->name, 'itemId' => (int) $master->id,
                'productCode' => (string) $master->code, 'unitsPerCase' => (int) $master->capacity_case, 'distributionBusinessKey' => $key]);
            $changes[] = ['row_id' => $id, 'product_code' => $import['product_code'], 'excel_row' => $import['excel_row'],
                'before' => $row?->row_data ?? [], 'after' => $data];
            if ($row) {
                $row->update(['row_data' => $data, 'updated_by' => $request->user()->id]);
            } else {
                WmsDistributionRow::create(['distribution_batch_id' => $batch->id, 'client_id' => $batch->client_id,
                    'warehouse_id' => $batch->warehouse_id, 'mode' => 'allocation', 'row_id' => $id,
                    'business_key' => $key, 'sort_order' => ++$nextOrder, 'source' => 'excel_import', 'source_key' => $data['sourceKey'],
                    'item_id' => $master->id, 'product_code' => (string) $master->code, 'row_data' => $data,
                    'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            }
        }
        if ($changes !== []) {
            $batch->update(['revision' => $batch->revision + 1, 'updated_by' => $request->user()->id]);
        }
        $summary = ['new_count' => $preview['new_count'], 'update_count' => $preview['update_count'],
            'unchanged_count' => $preview['unchanged_count'], 'skipped_count' => count($preview['skipped']), 'revision' => $batch->revision];
        $db->table('wms_distribution_imports')->insert(['distribution_batch_id' => $batch->id, 'execution_id' => $preview['execution_id'],
            'created_by' => $request->user()->id, 'file_name' => $preview['file_name'], 'file_hash' => $preview['file_hash'],
            'sheet_name' => $preview['sheet_name'], 'settings' => json_encode($preview['settings']),
            'summary' => json_encode($summary), 'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);

        return $this->success($summary);
    }

    private function authorizeUser(Request $request, PermissionService $permissions): void
    {
        abort_unless($request->user() && $permissions->check($request->user(), 'wms.distribution-adjustment.view'), 403);
        abort_unless(DB::connection('sakemaru')->table('warehouses')->where('id', (int) $request->user()->getSelectedWarehouseId())
            ->where('client_id', (int) config('app.client_id'))->where('code', '91')->where('is_active', true)->exists(), 403);
    }

    private function queues(array $rows): array
    {
        $ids = collect($rows)->flatMap(fn ($row) => array_merge($row['warehouseTransferQueueIds'] ?? [], $row['transferSlipQueueIds'] ?? []))->unique()->filter()->all();

        return $ids === [] ? [] : DB::connection('sakemaru')->table('stock_transfer_queue')
            ->where('client_id', (int) config('app.client_id'))->whereIn('id', $ids)
            ->get(['id', 'status', 'is_success', 'stock_transfer_id'])->keyBy('id')->all();
    }

    private function describe(WmsDistributionBatch $batch, ?array $queues = null): array
    {
        $batch->loadMissing(['creator', 'updater', 'rows' => fn ($q) => $q->select('id', 'distribution_batch_id', 'row_data')]);
        $rows = $batch->rows;
        $queues ??= $this->queues($rows->pluck('row_data')->all());

        return ['id' => $batch->id, 'name' => $batch->name, 'shipping_date' => $batch->shipping_date?->format('Y-m-d'),
            'responsible_department' => $batch->responsible_department, 'responsible_department_label' => $batch->responsibleDepartmentLabel(),
            'note' => $batch->note, 'revision' => $batch->revision, 'is_legacy' => $batch->is_legacy, 'archived' => (bool) $batch->archived_at,
            'creator' => $batch->is_legacy ? '既存データ移行' : ($batch->creator?->name ?? '不明'),
            'updater' => $batch->updater?->name ?? '既存データ移行', 'created_at' => $batch->created_at?->format('Y-m-d H:i'),
            'updated_at' => $batch->updated_at?->format('Y-m-d H:i'), 'row_count' => $rows->count(),
            'total' => $rows->sum(fn ($r) => array_sum(array_filter($r->row_data, fn ($k) => str_starts_with((string) $k, 'alloc_'), ARRAY_FILTER_USE_KEY))),
            'status' => $batch->archived_at ? '終了' : DistributionBatchService::status($rows->pluck('row_data')->all(), $queues)];
    }
}
