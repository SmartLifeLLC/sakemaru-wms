<div class="bg-white/90 backdrop-blur-sm p-10 rounded-xl shadow-2xl border border-gray-200">
    <div class="flex flex-col items-center mb-8">
        <img src="/images/logo-big.png" alt="酒丸蔵" class="h-16 mb-4 opacity-90">
        <h2 class="text-2xl font-bold text-gray-800 font-serif tracking-widest">おかえりなさい、蔵人さん</h2>
        <p class="text-gray-600 text-sm font-serif mt-2 tracking-wider">酒丸蔵の業務を開始します</p>
    </div>

    <form wire:submit="authenticate" class="space-y-6">
        <div>
            <label for="email" class="block text-sm font-bold text-gray-700 mb-2 font-serif tracking-wide">メールアドレス</label>
            <input type="email" id="email" wire:model="data.email" class="w-full px-4 py-3 rounded-lg bg-gray-50 border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-gray-800 font-serif" placeholder="user@smart-wms.com" required autocomplete="email">
            @error('data.email') <div class="text-red-700 text-xs mt-1 font-serif">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-bold text-gray-700 mb-2 font-serif tracking-wide">秘密の鍵</label>
            <input type="password" id="password" wire:model="data.password" class="w-full px-4 py-3 rounded-lg bg-gray-50 border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-gray-800 font-serif" placeholder="••••••••" required>
            @error('data.password') <div class="text-red-700 text-xs mt-1 font-serif">{{ $message }}</div> @enderror
        </div>



        <button type="submit" class="w-full py-4 rounded-lg bg-gray-900 text-white font-bold shadow-lg hover:bg-gray-800 transform hover:-translate-y-0.5 transition-all duration-200 font-serif tracking-widest text-lg">
            <span wire:loading.remove wire:target="authenticate">入場</span>
            <span wire:loading wire:target="authenticate">
                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                倉庫に入場中...
            </span>
        </button>
    </form>

    @if ($errors->has('data.login'))
        <div class="mt-6 p-4 rounded-lg bg-red-50 text-red-800 text-sm text-center font-serif border border-red-100">
            {{ $errors->first('data.login') }}
        </div>
    @endif
</div>
