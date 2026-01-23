<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HANDY ログイン</title>

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/handy/login-app.js'])

    <style>
        /* BHT-M60 3.2インチ WVGA (480x800) 最適化 */
        :root {
            --handy-width: 480px;
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
    </style>
</head>
<body>
    <div x-data="handyLoginApp()" x-init="init()"
         class="handy-container bg-slate-100 flex flex-col relative overflow-hidden">

        {{-- Header --}}
        <header class="bg-blue-600 text-white px-4 py-2 flex items-center justify-center shadow">
            <h1 class="text-handy-lg font-bold">HANDY システム</h1>
        </header>

        {{-- Main Content --}}
        <main class="flex-1 overflow-y-auto p-4 flex flex-col justify-center">
            {{-- Loading Overlay --}}
            <div x-show="isLoading"
                 class="absolute inset-0 bg-black/50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-4 shadow-xl flex items-center gap-3">
                    <div class="animate-spin rounded-full h-8 w-8 border-4 border-blue-600 border-t-transparent"></div>
                    <span class="text-handy-base font-bold" x-text="loadingMessage"></span>
                </div>
            </div>

            {{-- Login Form --}}
            <div class="bg-white rounded-lg shadow-lg p-4 border border-gray-200">
                <div class="text-center mb-4">
                    <i class="ph ph-user-circle text-handy-3xl text-blue-500 mb-1"></i>
                    <h2 class="text-handy-xl font-bold text-gray-800">ログイン</h2>
                    <p class="text-handy-xs text-gray-500">ピッカーコードでログイン</p>
                </div>

                <form @submit.prevent="login()">
                    {{-- Picker Code --}}
                    <div class="mb-3">
                        <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                            <i class="ph ph-identification-badge mr-1"></i>ピッカーコード
                        </label>
                        <input type="text"
                               x-model="loginForm.code"
                               placeholder="コード入力"
                               class="handy-input w-full border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:outline-none"
                               autofocus
                               required>
                    </div>

                    {{-- Password --}}
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                            <i class="ph ph-key mr-1"></i>パスワード
                        </label>
                        <input type="password"
                               x-model="loginForm.password"
                               placeholder="パスワード入力"
                               class="handy-input w-full border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:outline-none"
                               required>
                    </div>

                    {{-- Error Message --}}
                    <div x-show="loginError" class="mb-3 p-2 bg-red-50 border border-red-200 rounded">
                        <p class="text-red-600 text-handy-sm flex items-center gap-1">
                            <i class="ph ph-warning-circle"></i>
                            <span x-text="loginError"></span>
                        </p>
                    </div>

                    {{-- Login Button --}}
                    <button type="submit"
                            :disabled="isLoading || !loginForm.code || !loginForm.password"
                            class="handy-btn-lg w-full bg-blue-600 text-white font-bold rounded-lg shadow active:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <i class="ph ph-sign-in text-handy-xl"></i>
                        <span>ログイン</span>
                    </button>
                </form>
            </div>
        </main>
    </div>

    {{-- API Configuration --}}
    <script>
        window.HANDY_CONFIG = {
            apiKey: '{{ $apiKey }}',
            baseUrl: '{{ url('/api') }}',
        };
    </script>
</body>
</html>
