<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart WMS - Login</title>
    <style>
        /* 基本リセットとフォント設定 */
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            /* 背景画像の指定 - Removed as per request */
            /* background: url('/images/smart-wms-login.png') no-repeat center center fixed; */
            background-color: #020205;
            font-family: 'Rajdhani', 'Segoe UI', sans-serif;
            color: #fff;
        }

        @import url('https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap');

        /* Video Overlay - Initially Hidden */
        #video-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000; /* Higher than everything */
            background: #000;
            display: none; /* Hidden initially */
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.5s ease-in;
        }
        
        #intro-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            /* Fade edges to blend with background - softer gradient */
            -webkit-mask-image: radial-gradient(ellipse at center, black 60%, transparent 95%);
            mask-image: radial-gradient(ellipse at center, black 60%, transparent 95%);
        }

        /* Canvasは背景演出として薄く重ねる */
        canvas {
            display: block;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
            opacity: 0.6; /* 背景画像が見えるように半透明に */
            pointer-events: none;
        }

        /* ログインフォームのコンテナ */
        #login-container {
            position: absolute;
            top: 55%; /* 画像のロゴ位置を避けて少し下に配置 */
            left: 50%;
            transform: translate(-50%, -50%);
            width: 420px;
            
            /* 画像内の文字（Loguin等）を隠すために背景を少し濃くする */
            background: rgba(10, 15, 30, 0.85); 
            backdrop-filter: blur(10px);
            
            padding: 40px 50px;
            border-radius: 20px;
            z-index: 10;
            text-align: center;
            box-sizing: border-box;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);

            /* 最初から表示状態にする */
            opacity: 1;
            visibility: visible;
            transition: opacity 0.5s ease-out;
        }

        /* ロゴスタイル */
        h1 {
            font-weight: 700;
            font-size: 2.8rem;
            margin: 0 0 5px 0;
            text-transform: none;
            letter-spacing: 1px;
            color: #fff;
            text-shadow: 0 0 15px rgba(255, 255, 255, 0.8), 0 0 30px #00f2ff;
            background: linear-gradient(180deg, #ffffff 0%, #b0c4de 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* AIチップアイコン風の装飾 */
        .ai-chip-icon {
            font-size: 1.5rem;
            color: #00f2ff;
            border: 2px solid #00f2ff;
            border-radius: 8px;
            padding: 5px 15px;
            display: inline-block;
            margin-bottom: 15px;
            box-shadow: 0 0 15px rgba(0, 242, 255, 0.5);
            background: rgba(0, 20, 40, 0.5);
            font-weight: bold;
        }

        .input-box {
            position: relative;
            margin-bottom: 20px;
            text-align: left;
        }

        /* 入力フィールド */
        .input-box input {
            width: 100%;
            padding: 15px 20px;
            font-size: 1rem;
            color: #333;
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 5px;
            outline: none;
            transition: 0.3s;
            font-family: 'Segoe UI', sans-serif;
            box-sizing: border-box;
        }

        .input-box input:focus {
            background: #fff;
            box-shadow: 0 0 15px rgba(0, 242, 255, 0.5);
            transform: scale(1.02);
        }

        .input-box input::placeholder {
            color: #888;
        }

        /* ボタン */
        button {
            width: 100%;
            padding: 15px;
            margin-top: 10px;
            background: linear-gradient(135deg, #2ebfec 0%, #0070c0 100%);
            border: none;
            border-radius: 30px;
            color: #fff;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            text-transform: lowercase;
            letter-spacing: 1px;
            box-shadow: 0 5px 20px rgba(0, 112, 192, 0.4);
            font-family: 'Rajdhani', sans-serif;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 112, 192, 0.6);
            filter: brightness(1.1);
        }
        
        .error-message {
            color: #ff4444;
            font-size: 0.9rem;
            margin-top: 5px;
        }

    </style>
    @livewireStyles
</head>
<body>

    <div id="video-overlay">
        <video id="intro-video" playsinline>
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

    <!-- Canvas: 背景画像の上でパーティクルを飛ばす -->
    <canvas id="bgCanvas"></canvas>

    {{ $slot }}

    @livewireScripts

    <script>
        /**
         * Canvas Animation
         * 背景画像の上で、AIチップのような電子的なパーティクルを飛ばす
         */
        const canvas = document.getElementById('bgCanvas');
        const ctx = canvas.getContext('2d');
        let width, height;
        let particles = [];
        let mouse = { x: null, y: null };

        const config = {
            particleCount: 60,
            connectionDistance: 150,
            colors: { ai: '0, 242, 255', text: '255, 255, 255' }
        };

        function resize() {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        }
        window.addEventListener('resize', resize);
        resize();

        window.addEventListener('mousemove', (e) => {
            mouse.x = e.clientX;
            mouse.y = e.clientY;
        });

        class Particle {
            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 0.5;
                this.vy = (Math.random() - 0.5) * 0.5;
                this.size = Math.random() * 2;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > width) this.vx = -this.vx;
                if (this.y < 0 || this.y > height) this.vy = -this.vy;
            }
            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(${config.colors.ai}, 0.5)`;
                ctx.fill();
            }
        }

        function initParticles() {
            particles = [];
            for (let i = 0; i < config.particleCount; i++) {
                particles.push(new Particle());
            }
        }

        function animate() {
            ctx.clearRect(0, 0, width, height);
            
            // 背景画像があるので、Canvasは装飾のみ描画
            
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                p.update();
                p.draw();

                // パーティクル同士の結合
                for (let j = i; j < particles.length; j++) {
                    const p2 = particles[j];
                    const d = Math.hypot(p.x - p2.x, p.y - p2.y);
                    if (d < config.connectionDistance) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(${config.colors.ai}, ${0.2 * (1 - d/config.connectionDistance)})`;
                        ctx.lineWidth = 0.5;
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(p2.x, p2.y);
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(animate);
        }

        initParticles();
        animate();
        
        // Login Success Handling
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('login-success', (event) => {
                const url = event.url; // Access the first argument directly if it's not an object, or event[0].url depending on Livewire version
                // Livewire v3 usually passes arguments as spread.
                // Let's check how dispatch works. $this->dispatch('name', param: value) -> event.param
                
                const targetUrl = event.url || event[0]?.url;

                const loginContainer = document.getElementById('login-container');
                const videoOverlay = document.getElementById('video-overlay');
                const video = document.getElementById('intro-video');
                
                // Hide login
                loginContainer.style.opacity = '0';
                setTimeout(() => {
                    loginContainer.style.display = 'none';
                    
                    // Show video
                    videoOverlay.style.display = 'flex';
                    // Trigger reflow
                    videoOverlay.offsetHeight;
                    videoOverlay.style.opacity = '1';
                    
                    video.play().then(() => {
                        // Video playing
                    }).catch(e => {
                        console.error("Video play failed", e);
                        window.location.href = targetUrl;
                    });
                    
                    video.onended = function() {
                        window.location.href = targetUrl;
                    };
                }, 500);
            });
        });

    </script>
</body>
</html>
