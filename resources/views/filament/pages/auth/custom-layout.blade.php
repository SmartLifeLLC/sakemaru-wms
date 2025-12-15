<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>酒丸蔵 - Sign In</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap');
        body {
            font-family: 'Rajdhani', 'Segoe UI', sans-serif;
        }

        #video-overlay {
            display: none;
            opacity: 0;
            transition: opacity 0.5s ease-in;
        }
    </style>
    @livewireStyles
</head>
<body class="h-screen w-full flex items-center justify-center overflow-hidden relative bg-cover bg-center" style="background-image: url('/images/login-bg.jpeg')">

    <!-- Background Overlay -->
    <div class="absolute inset-0 bg-black/40 z-0"></div>

    <!-- Optional: Brand/Slogan Overlay (Top Left or floating) -->
    <div class="absolute top-10 left-10 text-white z-10 hidden lg:block">
        <h2 class="text-4xl font-bold font-serif mb-2">酒丸蔵</h2>
        <p class="text-xl tracking-widest font-serif opacity-90">次世代倉庫管理システム</p>
    </div>

    <!-- Main Content: Centered Form -->
    <div class="relative z-10 w-full max-w-md p-4">
         {{ $slot }}
    </div>

    <!-- Video Overlay -->
    <div id="video-overlay" class="fixed inset-0 z-[2000] bg-black flex items-center justify-center">
        <video id="intro-video" class="w-full h-full object-cover">
            @php
                $videos = [
                    'https://smart-life-public-resources.s3.ap-northeast-1.amazonaws.com/movies/smart-wms-opening.mp4',
                    'https://smart-life-public-resources.s3.ap-northeast-1.amazonaws.com/movies/smart-wms-opening2.mp4',
                    'https://smart-life-public-resources.s3.ap-northeast-1.amazonaws.com/movies/smart-wms-opening3.mp4',
                    'https://smart-life-public-resources.s3.ap-northeast-1.amazonaws.com/movies/smart-wms-opening4.mp4',
                ];
                $randomVideo = $videos[array_rand($videos)];
            @endphp
            <source src="{{ $randomVideo }}" type="video/mp4">
        </video>
    </div>

    @livewireScripts

    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('login-success', (event) => {
                const targetUrl = event.url || event[0]?.url;
                const videoOverlay = document.getElementById('video-overlay');
                const video = document.getElementById('intro-video');

                // Show video
                videoOverlay.style.display = 'flex';
                // Trigger reflow
                videoOverlay.offsetHeight;
                videoOverlay.style.opacity = '1';

                video.play().then(() => {
                }).catch(e => {
                    console.error("Video play failed", e);
                    window.location.href = targetUrl;
                });

                video.onended = function() {
                    window.location.href = targetUrl;
                };
            });
        });
    </script>
</body>
</html>
