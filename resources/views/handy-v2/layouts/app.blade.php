<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="screen-orientation" content="landscape">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1e293b">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>WMS Handy V2</title>

    <script>
        window.HANDY_V2_CONFIG = {
            apiKey: '{{ $apiKey }}',
            baseUrl: '{{ url('/api') }}',
        };
    </script>

    @vite(['resources/css/handy-v2/app.css', 'resources/js/handy-v2/app.js'])
</head>
<body>
    @yield('content')

    <script>
        // Service Worker registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then((reg) => console.log('SW registered:', reg.scope))
                    .catch((err) => console.warn('SW registration failed:', err));
            });
        }

        // Offline/Online detection
        function showOfflineToast(isOnline) {
            // Dispatch to Alpine notification store if available
            const app = document.querySelector('[x-data]');
            if (app && app.__x) {
                const data = app.__x.$data || app._x_dataStack?.[0];
                if (data?.notification) {
                    if (isOnline) {
                        data.notification.success('オンラインに復帰しました');
                    } else {
                        data.notification.warning('オフラインです。一部機能が制限されます。', 0);
                    }
                }
            }
        }

        window.addEventListener('online', () => showOfflineToast(true));
        window.addEventListener('offline', () => showOfflineToast(false));
    </script>
</body>
</html>
