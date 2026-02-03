{{-- Login Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'login'">
    <div class="p-4 flex flex-col h-full justify-center">
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
    </div>
</template>
