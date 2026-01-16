<div class="max-h-96 overflow-y-auto">
    @if(empty($errors))
        <p class="text-gray-500 dark:text-gray-400">エラーはありません</p>
    @else
        <div class="space-y-1 text-sm">
            @foreach($errors as $error)
                <div class="text-danger-600 dark:text-danger-400">{{ $error }}</div>
            @endforeach
        </div>
        @if(count($errors) >= 100)
            <p class="mt-4 text-gray-500 dark:text-gray-400 text-xs">
                ※ 最初の100件のみ表示しています
            </p>
        @endif
    @endif
</div>
