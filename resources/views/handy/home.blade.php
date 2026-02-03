<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HANDY ホーム</title>

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/handy/home-app.js'])

    <style>
        :root {
            --handy-width: 480px;
            --header-height: 40px;
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

        .handy-container {
            width: 480px;
            height: 100vh;
            max-width: 480px;
        }

        .handy-header {
            height: var(--header-height);
            padding: var(--spacing-1) var(--spacing-3);
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

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body>
    <div x-data="handyHomeApp()" x-init="init()"
         class="handy-container bg-white flex flex-col relative overflow-hidden">

        {{-- Header --}}
        <header class="handy-header bg-blue-600 text-white flex items-center justify-between shadow">
            <h1 class="text-handy-lg font-bold" x-text="getHeaderTitle()"></h1>
            <button @click="logout()" class="text-handy-sm bg-blue-700 px-2 py-1 rounded">
                <i class="ph ph-sign-out"></i> ログアウト
            </button>
        </header>

        {{-- Main Content --}}
        <main class="flex-1 overflow-y-auto no-scrollbar bg-slate-50 relative">
            {{-- Loading Overlay --}}
            <div x-show="isLoading"
                 class="absolute inset-0 bg-black/50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-4 shadow-xl flex items-center gap-3">
                    <div class="animate-spin rounded-full h-8 w-8 border-4 border-blue-600 border-t-transparent"></div>
                    <span class="text-handy-base font-bold" x-text="loadingMessage"></span>
                </div>
            </div>

            {{-- Warehouse Selection --}}
            <template x-if="currentScreen === 'warehouse'">
                <div class="p-4">
                    <h2 class="text-handy-lg font-bold text-gray-800 mb-3">
                        <i class="ph ph-warehouse mr-1"></i>倉庫を選択
                    </h2>
                    <div class="space-y-2">
                        <template x-for="wh in warehouses" :key="wh.id">
                            <button @click="selectWarehouse(wh)"
                                    :class="selectedWarehouse?.id === wh.id ? 'bg-blue-100 border-blue-500' : 'bg-white border-gray-200'"
                                    class="w-full p-3 border-2 rounded-lg text-left flex items-center gap-2 active:bg-blue-50">
                                <i class="ph ph-buildings text-handy-xl text-blue-600"></i>
                                <span class="text-handy-base font-bold" x-text="wh.name"></span>
                            </button>
                        </template>
                    </div>
                    <div x-show="warehouses.length === 0 && !isLoading" class="text-center py-8 text-gray-400">
                        <i class="ph ph-warning text-handy-2xl mb-2"></i>
                        <p class="text-handy-sm">倉庫が見つかりません</p>
                    </div>
                </div>
            </template>

            {{-- Menu Selection --}}
            <template x-if="currentScreen === 'menu'">
                <div class="p-4">
                    {{-- Selected Warehouse Display --}}
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-handy-xs text-gray-500">選択中の倉庫</p>
                            <p class="text-handy-lg font-bold text-blue-800" x-text="selectedWarehouse?.name"></p>
                        </div>
                        <button @click="changeWarehouse()" class="text-handy-sm text-blue-600 underline">
                            変更
                        </button>
                    </div>

                    {{-- Menu Buttons --}}
                    <div class="space-y-3">
                        <button @click="goToIncoming()"
                                class="w-full p-4 bg-green-500 text-white rounded-lg shadow-lg active:bg-green-600 flex items-center gap-3">
                            <i class="ph ph-package text-handy-3xl"></i>
                            <div class="text-left">
                                <p class="text-handy-xl font-bold">入荷</p>
                                <p class="text-handy-sm opacity-80">入庫処理を行う</p>
                            </div>
                        </button>

                        <button @click="goToOutgoing()"
                                class="w-full p-4 bg-orange-500 text-white rounded-lg shadow-lg active:bg-orange-600 flex items-center gap-3">
                            <i class="ph ph-truck text-handy-3xl"></i>
                            <div class="text-left">
                                <p class="text-handy-xl font-bold">出荷</p>
                                <p class="text-handy-sm opacity-80">ピッキング・出荷処理を行う</p>
                            </div>
                        </button>
                    </div>
                </div>
            </template>
        </main>

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
             class="fixed bottom-4 left-1/2 transform -translate-x-1/2 px-4 py-2 rounded-lg text-white shadow-lg z-50">
            <span x-text="notification.message"></span>
        </div>
    </div>

    {{-- API Configuration --}}
    <script>
        window.HANDY_CONFIG = {
            apiKey: '{{ $apiKey }}',
            baseUrl: '{{ url('/api') }}',
            authKey: {!! $authKey ? "'" . e($authKey) . "'" : 'null' !!},
        };
    </script>
</body>
</html>
