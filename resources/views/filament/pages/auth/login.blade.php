<div id="login-container">
    <!-- 画像内のロゴを再現（コンテナ背景で隠しつつ上に表示） -->
    <div class="ai-chip-icon">AI</div>
    <h1>Smart WMS</h1>
    
    <form wire:submit="authenticate">
        <div class="input-box">
            <input type="email" id="email" wire:model="data.email" placeholder="Email Account" required autocomplete="email">
            @error('data.email') <div class="error-message">{{ $message }}</div> @enderror
        </div>
        <div class="input-box">
            <input type="password" id="password" wire:model="data.password" placeholder="Password" required>
            @error('data.password') <div class="error-message">{{ $message }}</div> @enderror
        </div>
        <button type="submit">
            <span wire:loading.remove wire:target="authenticate">Login</span>
            <span wire:loading wire:target="authenticate">Authenticating...</span>
        </button>
    </form>
    
    @if ($errors->has('data.login'))
        <div class="error-message" style="text-align: center; margin-top: 15px;">
            {{ $errors->first('data.login') }}
        </div>
    @endif
</div>
