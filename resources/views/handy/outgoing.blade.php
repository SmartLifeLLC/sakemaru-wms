<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HANDY 出荷処理システム</title>

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/handy/outgoing-app.js'])

    <style>
        :root {
            --handy-width: 480px;
            --header-height: 40px;
            --footer-height: 40px;
            --font-xs: 13px;
            --font-sm: 15px;
            --font-base: 17px;
            --font-lg: 20px;
            --font-xl: 22px;
            --font-2xl: 27px;
            --font-3xl: 31px;
            --spacing-1: 4px;
            --spacing-2: 8px;
            --spacing-3: 12px;
            --spacing-4: 16px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        body {
            background-color: #1f2937;
            font-family: 'Hiragino Kaku Gothic ProN', 'メイリオ', sans-serif;
            font-size: var(--font-base);
            -webkit-font-smoothing: antialiased;
            -webkit-tap-highlight-color: transparent;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] { -moz-appearance: textfield; }

        .handy-container {
            width: 480px;
            height: 100vh;
            max-width: 480px;
        }

        .handy-header {
            height: var(--header-height);
            padding: var(--spacing-1) var(--spacing-3);
        }

        .handy-main {
            height: calc(100vh - var(--header-height) - var(--footer-height));
        }

        .text-handy-xs { font-size: var(--font-xs); }
        .text-handy-sm { font-size: var(--font-sm); }
        .text-handy-base { font-size: var(--font-base); }
        .text-handy-lg { font-size: var(--font-lg); }
        .text-handy-xl { font-size: var(--font-xl); }
        .text-handy-2xl { font-size: var(--font-2xl); }
        .text-handy-3xl { font-size: var(--font-3xl); }

        .handy-btn {
            min-height: 44px;
            padding: var(--spacing-2) var(--spacing-3);
            font-size: var(--font-lg);
        }

        .handy-btn-lg {
            min-height: 52px;
            padding: var(--spacing-3) var(--spacing-4);
            font-size: var(--font-xl);
        }

        .handy-input {
            height: 44px;
            padding: var(--spacing-2) var(--spacing-3);
            font-size: var(--font-lg);
        }

        .handy-input-lg {
            height: 52px;
            font-size: var(--font-2xl);
        }

        .handy-card {
            padding: var(--spacing-2) var(--spacing-3);
            margin-bottom: var(--spacing-2);
        }
    </style>
</head>
<body>
    <div x-data="handyOutgoingApp()" x-init="init()"
         class="handy-container bg-white flex flex-col relative overflow-hidden">

        {{-- Header --}}
        <header class="handy-header bg-orange-600 text-white flex items-center justify-between shadow">
            <div class="flex items-center gap-2">
                <button x-show="canGoBack()" @click="goBack()" class="p-1">
                    <i class="ph ph-caret-left text-handy-xl"></i>
                </button>
                <h1 class="text-handy-lg font-bold truncate" x-text="getHeaderTitle()"></h1>
            </div>
            <button @click="goHome()" class="text-handy-sm bg-orange-700 px-2 py-1 rounded">
                <i class="ph ph-house"></i>
            </button>
        </header>

        {{-- Main Content --}}
        <main class="handy-main overflow-y-auto no-scrollbar bg-slate-50 relative">
            {{-- Loading Overlay --}}
            <div x-show="isLoading"
                 class="absolute inset-0 bg-black/50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-4 shadow-xl flex items-center gap-3">
                    <div class="animate-spin rounded-full h-8 w-8 border-4 border-orange-600 border-t-transparent"></div>
                    <span class="text-handy-base font-bold" x-text="loadingMessage"></span>
                </div>
            </div>

            {{-- 1. Task List Screen --}}
            <template x-if="currentScreen === 'task-list'">
                <div class="p-3">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-handy-lg font-bold text-gray-800">
                            <i class="ph ph-list-checks mr-1"></i>ピッキングタスク
                        </h2>
                        <button @click="refreshTasks()" class="text-orange-600">
                            <i class="ph ph-arrows-clockwise text-handy-xl"></i>
                        </button>
                    </div>

                    <div class="space-y-2" x-ref="taskList">
                        <template x-for="(task, index) in tasks" :key="task.wave.wms_picking_task_id">
                            <button @click="selectTask(task)"
                                    :data-task-index="index"
                                    :class="selectedTaskIndex === index ? 'ring-2 ring-orange-500' : ''"
                                    class="w-full bg-white border border-gray-200 rounded-lg p-3 text-left active:bg-orange-50 shadow-sm">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-handy-base font-bold text-orange-700" x-text="task.course.name"></span>
                                    <span class="text-handy-xs bg-gray-100 px-2 py-0.5 rounded" x-text="task.course.code"></span>
                                </div>
                                <div class="flex items-center justify-between text-handy-sm text-gray-600">
                                    <span x-text="task.picking_area.name"></span>
                                    <span class="font-bold" x-text="task.picking_list.length + '点'"></span>
                                </div>
                            </button>
                        </template>
                    </div>

                    <div x-show="tasks.length === 0 && !isLoading" class="text-center py-8 text-gray-400">
                        <i class="ph ph-clipboard text-handy-3xl mb-2"></i>
                        <p class="text-handy-base">タスクがありません</p>
                    </div>
                </div>
            </template>

            {{-- 2. Picking Items Screen --}}
            <template x-if="currentScreen === 'picking'">
                <div class="p-3">
                    {{-- Task Info --}}
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 mb-3">
                        <div class="flex items-center justify-between">
                            <span class="text-handy-sm text-gray-600" x-text="currentTask?.course.name"></span>
                            <span class="text-handy-sm font-bold" x-text="getProgressText()"></span>
                        </div>
                    </div>

                    {{-- Current Item --}}
                    <template x-if="currentItem">
                        <div class="bg-white border-2 border-orange-300 rounded-lg p-3 mb-3 shadow">
                            {{-- Item Image --}}
                            <div x-show="currentItem.images && currentItem.images.length > 0" class="mb-2">
                                <img :src="currentItem.images[0]" alt="商品画像" class="w-full h-32 object-contain rounded bg-gray-100">
                            </div>

                            {{-- Item Info --}}
                            <div class="mb-3">
                                <p class="text-handy-xl font-bold text-gray-900" x-text="currentItem.item_name"></p>
                                <div class="flex items-center gap-2 mt-1 text-handy-sm text-gray-600">
                                    <span x-show="currentItem.jan_code" x-text="'JAN: ' + currentItem.jan_code"></span>
                                    <span x-show="currentItem.volume" x-text="currentItem.volume"></span>
                                </div>
                                <div x-show="currentItem.destination_warehouse" class="mt-1">
                                    <span class="text-handy-sm bg-purple-100 text-purple-700 px-2 py-0.5 rounded">
                                        <i class="ph ph-arrow-right"></i>
                                        <span x-text="currentItem.destination_warehouse"></span>
                                    </span>
                                </div>
                            </div>

                            {{-- Quantity Display --}}
                            <div class="bg-gray-100 rounded-lg p-3 mb-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-handy-base text-gray-600">予定数</span>
                                    <span class="text-handy-2xl font-bold text-gray-900">
                                        <span x-text="currentItem.planned_qty"></span>
                                        <span class="text-handy-base" x-text="getQtyTypeLabel(currentItem.planned_qty_type)"></span>
                                    </span>
                                </div>
                            </div>

                            {{-- Quantity Input --}}
                            <div class="mb-3">
                                <label class="block text-handy-sm font-bold text-gray-700 mb-1">ピッキング数量</label>
                                <div class="flex items-center gap-2">
                                    <button @click="adjustQty(-1)"
                                            class="handy-btn bg-gray-200 text-gray-700 rounded-lg font-bold text-handy-2xl w-14">-</button>
                                    <input type="number"
                                           x-model.number="pickedQty"
                                           class="handy-input handy-input-lg flex-1 border-2 border-gray-300 rounded-lg text-center focus:border-orange-500 focus:outline-none">
                                    <button @click="adjustQty(1)"
                                            class="handy-btn bg-gray-200 text-gray-700 rounded-lg font-bold text-handy-2xl w-14">+</button>
                                </div>
                            </div>

                            {{-- Shortage Note --}}
                            <div x-show="pickedQty < currentItem.planned_qty" class="mb-3">
                                <p class="text-handy-sm text-red-600 font-bold mb-1">
                                    <i class="ph ph-warning"></i>
                                    欠品: <span x-text="currentItem.planned_qty - pickedQty"></span>
                                    <span x-text="getQtyTypeLabel(currentItem.planned_qty_type)"></span>
                                </p>
                            </div>

                            {{-- Action Buttons --}}
                            <div class="flex gap-2">
                                <button @click="submitPicking()"
                                        :disabled="isLoading"
                                        class="flex-1 handy-btn-lg bg-orange-600 text-white font-bold rounded-lg shadow active:bg-orange-700 disabled:bg-gray-400 flex items-center justify-center gap-2">
                                    <i class="ph ph-check-circle text-handy-xl"></i>
                                    <span>確定</span>
                                </button>
                            </div>
                        </div>
                    </template>

                    {{-- Remaining Items Preview --}}
                    <div x-show="remainingItems.length > 0" class="mt-3">
                        <h3 class="text-handy-sm font-bold text-gray-600 mb-2">次の商品</h3>
                        <div class="space-y-1">
                            <template x-for="item in remainingItems.slice(0, 3)" :key="item.wms_picking_item_result_id">
                                <div class="bg-white border border-gray-200 rounded p-2 text-handy-sm">
                                    <p class="truncate" x-text="item.item_name"></p>
                                    <span class="text-gray-500" x-text="item.planned_qty + ' ' + getQtyTypeLabel(item.planned_qty_type)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            {{-- 3. Task Complete Screen --}}
            <template x-if="currentScreen === 'complete'">
                <div class="p-4 flex flex-col items-center justify-center h-full">
                    <div class="bg-white rounded-lg shadow-lg p-6 text-center w-full max-w-sm">
                        <div :class="hasShortage ? 'text-yellow-500' : 'text-green-500'" class="mb-4">
                            <i :class="hasShortage ? 'ph-warning-circle' : 'ph-check-circle'" class="ph text-6xl"></i>
                        </div>
                        <h2 class="text-handy-2xl font-bold mb-2" x-text="hasShortage ? 'ピッキング完了（欠品あり）' : 'ピッキング完了'"></h2>
                        <p class="text-handy-base text-gray-600 mb-4" x-text="currentTask?.course.name"></p>

                        <div class="bg-gray-100 rounded-lg p-3 mb-4">
                            <div class="flex justify-between text-handy-base">
                                <span>完了数</span>
                                <span class="font-bold" x-text="completedCount + '点'"></span>
                            </div>
                            <div x-show="shortageCount > 0" class="flex justify-between text-handy-base text-red-600 mt-1">
                                <span>欠品数</span>
                                <span class="font-bold" x-text="shortageCount + '点'"></span>
                            </div>
                        </div>

                        <button @click="completeTask()"
                                :disabled="isLoading"
                                class="handy-btn-lg w-full bg-orange-600 text-white font-bold rounded-lg shadow active:bg-orange-700 disabled:bg-gray-400 flex items-center justify-center gap-2">
                            <i class="ph ph-flag-checkered text-handy-xl"></i>
                            <span>タスク完了</span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- 4. Result Screen --}}
            <template x-if="currentScreen === 'result'">
                <div class="p-4 flex flex-col items-center justify-center h-full">
                    <div class="bg-white rounded-lg shadow-lg p-6 text-center w-full max-w-sm">
                        <div class="text-green-500 mb-4">
                            <i class="ph ph-check-circle text-6xl"></i>
                        </div>
                        <h2 class="text-handy-2xl font-bold mb-4">出荷完了</h2>

                        <button @click="finishAndGoBack()"
                                class="handy-btn-lg w-full bg-orange-600 text-white font-bold rounded-lg shadow active:bg-orange-700 flex items-center justify-center gap-2">
                            <i class="ph ph-arrow-left text-handy-xl"></i>
                            <span>タスク一覧へ戻る</span>
                        </button>
                    </div>
                </div>
            </template>
        </main>

        {{-- Footer --}}
        <footer class="h-10 bg-gray-800 text-white flex items-center justify-center text-handy-xs">
            <span x-text="picker?.name || ''"></span>
            <span class="mx-2">|</span>
            <span x-text="selectedWarehouse?.name || ''"></span>
        </footer>

        {{-- Notification Toast --}}
        <div x-show="notification.show"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             :class="{
                 'bg-green-500': notification.type === 'success',
                 'bg-red-500': notification.type === 'error',
                 'bg-blue-500': notification.type === 'info'
             }"
             class="fixed bottom-12 left-1/2 transform -translate-x-1/2 px-4 py-2 rounded-lg text-white shadow-lg z-50">
            <span x-text="notification.message"></span>
        </div>
    </div>

    {{-- API Configuration --}}
    <script>
        window.HANDY_CONFIG = {
            apiKey: '{{ $apiKey }}',
            baseUrl: '{{ url('/api') }}',
            authKey: {!! $authKey ? "'" . e($authKey) . "'" : 'null' !!},
            warehouseId: {!! $warehouseId ? (int)$warehouseId : 'null' !!}
        };
    </script>
</body>
</html>
