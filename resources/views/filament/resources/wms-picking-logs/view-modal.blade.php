<div class="space-y-6">
    {{-- 基本情報 --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">基本情報</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">日時</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->created_at->format('Y-m-d H:i:s') }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">操作種類</dt>
                <dd class="mt-1">
                    <x-filament::badge :color="match($record->action_type) {
                        'LOGIN' => 'success',
                        'LOGOUT' => 'gray',
                        'START' => 'info',
                        'PICK' => 'warning',
                        'COMPLETE' => 'success',
                        default => 'gray'
                    }">
                        {{ $record->action_type }}
                    </x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">エンドポイント</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->endpoint }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">HTTPメソッド</dt>
                <dd class="mt-1">
                    <x-filament::badge>{{ $record->http_method }}</x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">HTTPステータス</dt>
                <dd class="mt-1">
                    <x-filament::badge :color="match(true) {
                        $record->response_status_code >= 200 && $record->response_status_code < 300 => 'success',
                        $record->response_status_code >= 400 && $record->response_status_code < 500 => 'warning',
                        $record->response_status_code >= 500 => 'danger',
                        default => 'gray'
                    }">
                        {{ $record->response_status_code }}
                    </x-filament::badge>
                </dd>
            </div>
        </dl>
    </div>

    {{-- ピッカー情報 --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">ピッカー情報</h3>
        <dl class="grid grid-cols-3 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッカーID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picker_id }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッカーコード</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picker_code }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッカー名</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picker_name }}</dd>
            </div>
        </dl>
    </div>

    {{-- タスク情報 --}}
    @if($record->picking_task_id || $record->wave_id)
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">タスク情報</h3>
        <dl class="grid grid-cols-2 gap-4">
            @if($record->picking_task_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッキングタスクID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picking_task_id }}</dd>
            </div>
            @endif
            @if($record->wave_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Wave ID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->wave_id }}</dd>
            </div>
            @endif
            @if($record->earning_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">売上ID（伝票ID）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->earning_id }}</dd>
            </div>
            @endif
            @if($record->picking_item_result_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッキング品目結果ID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picking_item_result_id }}</dd>
            </div>
            @endif
        </dl>
    </div>
    @endif

    {{-- 商品情報 --}}
    @if($record->item_id)
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">商品情報</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">商品ID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->item_id }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">商品コード</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->item_code }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">商品名</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->item_name }}</dd>
            </div>
            @if($record->real_stock_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">実在庫ID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->real_stock_id }}</dd>
            </div>
            @endif
            @if($record->location_id)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ロケーションID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->location_id }}</dd>
            </div>
            @endif
        </dl>
    </div>
    @endif

    {{-- 数量情報 --}}
    @if($record->planned_qty || $record->picked_qty)
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">数量情報</h3>
        <dl class="grid grid-cols-3 gap-4">
            @if($record->planned_qty)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">予定数</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ number_format($record->planned_qty, 2) }} {{ $record->planned_qty_type }}</dd>
            </div>
            @endif
            @if($record->picked_qty)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッキング数</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ number_format($record->picked_qty, 2) }} {{ $record->picked_qty_type }}</dd>
            </div>
            @endif
            @if($record->shortage_qty)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">欠品数</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ number_format($record->shortage_qty, 2) }}</dd>
            </div>
            @endif
        </dl>
    </div>
    @endif

    {{-- 在庫変動 --}}
    @if($record->stock_qty_before !== null || $record->stock_qty_after !== null)
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">在庫変動</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">在庫数（変更前）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->stock_qty_before !== null ? number_format($record->stock_qty_before, 2) : '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">在庫数（変更後）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->stock_qty_after !== null ? number_format($record->stock_qty_after, 2) : '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">引当数（変更前）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->reserved_qty_before !== null ? number_format($record->reserved_qty_before, 2) : '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">引当数（変更後）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->reserved_qty_after !== null ? number_format($record->reserved_qty_after, 2) : '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッキング中数（変更前）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picking_qty_before !== null ? number_format($record->picking_qty_before, 2) : '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ピッキング中数（変更後）</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->picking_qty_after !== null ? number_format($record->picking_qty_after, 2) : '-' }}</dd>
            </div>
        </dl>
    </div>
    @endif

    {{-- ステータス変更 --}}
    @if($record->status_before || $record->status_after)
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">ステータス変更</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">変更前ステータス</dt>
                <dd class="mt-1">
                    <x-filament::badge>{{ $record->status_before ?? '-' }}</x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">変更後ステータス</dt>
                <dd class="mt-1">
                    <x-filament::badge>{{ $record->status_after ?? '-' }}</x-filament::badge>
                </dd>
            </div>
        </dl>
    </div>
    @endif

    {{-- クライアント情報 --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">クライアント情報</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">IPアドレス</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->ip_address ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">デバイスID</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->device_id ?? '-' }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ユーザーエージェント</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 break-all">{{ $record->user_agent ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    {{-- リクエストデータ --}}
    @if(!empty($record->request_data))
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">リクエストデータ</h3>
        <pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto">{{ json_encode($record->request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- レスポンスデータ --}}
    @if(!empty($record->response_data))
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="text-lg font-semibold mb-3">レスポンスデータ</h3>
        <pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto">{{ json_encode($record->response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif
</div>
