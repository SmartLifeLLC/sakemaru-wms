<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'HANDY 入庫処理システム')</title>

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/handy/incoming-app.js'])

    <style>
        /* BHT-M60 3.2インチ WVGA (480x800) 最適化 */
        :root {
            /* Layout */
            --handy-width: 480px;
            --header-height: 40px;
            --footer-height: 40px;

            /* Font sizes (adjust here to change all font sizes) */
            --font-xs: 13px;
            --font-sm: 15px;
            --font-base: 17px;
            --font-lg: 20px;
            --font-xl: 22px;
            --font-2xl: 27px;
            --font-3xl: 31px;

            /* Spacing */
            --spacing-1: 4px;
            --spacing-2: 8px;
            --spacing-3: 12px;
            --spacing-4: 16px;
        }

        * {
            box-sizing: border-box;
        }

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

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] {
            -moz-appearance: textfield;
        }

        /* Handy specific overrides */
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

        /* Typography */
        .text-handy-xs { font-size: var(--font-xs); }
        .text-handy-sm { font-size: var(--font-sm); }
        .text-handy-base { font-size: var(--font-base); }
        .text-handy-lg { font-size: var(--font-lg); }
        .text-handy-xl { font-size: var(--font-xl); }
        .text-handy-2xl { font-size: var(--font-2xl); }
        .text-handy-3xl { font-size: var(--font-3xl); }

        /* Buttons optimized for touch */
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

        /* Input fields */
        .handy-input {
            height: 44px;
            padding: var(--spacing-2) var(--spacing-3);
            font-size: var(--font-lg);
        }

        .handy-input-sm {
            height: 36px;
            padding: var(--spacing-1) var(--spacing-2);
            font-size: var(--font-xs);
        }

        .handy-input-sm::placeholder {
            font-size: var(--font-xs);
        }

        .handy-input-lg {
            height: 52px;
            font-size: var(--font-2xl);
        }

        /* Cards */
        .handy-card {
            padding: var(--spacing-2) var(--spacing-3);
            margin-bottom: var(--spacing-2);
        }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
