<div class="wms-login">
    <div class="wms-login-card">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800">WMS Handy</h1>
            <p class="text-sm text-gray-500 mt-1">ピッカーコードでログイン</p>
        </div>

        <form @submit.prevent="login()">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">ピッカーコード</label>
                <input
                    type="text"
                    x-model="loginForm.code"
                    class="wms-input"
                    placeholder="コードを入力"
                    autocomplete="username"
                    inputmode="numeric"
                    required
                >
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
                <input
                    type="password"
                    x-model="loginForm.password"
                    class="wms-input"
                    placeholder="パスワードを入力"
                    autocomplete="current-password"
                    required
                >
            </div>

            <template x-if="loginError">
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm" x-text="loginError"></div>
            </template>

            <button
                type="submit"
                class="wms-btn wms-btn-primary w-full"
                :disabled="isLoading || !loginForm.code || !loginForm.password"
            >
                <template x-if="isLoading">
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </template>
                ログイン
            </button>
        </form>

        <div class="mt-4 text-center text-xs text-gray-400">
            WMS Handy V2
        </div>
    </div>
</div>
