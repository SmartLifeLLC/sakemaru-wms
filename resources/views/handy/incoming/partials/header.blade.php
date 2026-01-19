{{-- Header Component - 480x800 optimized --}}
<header class="handy-header bg-blue-700 text-white flex items-center justify-between shrink-0 shadow-md z-10">
    <div class="flex items-center gap-1 flex-1 min-w-0">
        {{-- Back Button --}}
        <button x-show="currentScreen !== 'login' && currentScreen !== 'warehouse'"
                @click="goBack()"
                class="p-1 active:bg-blue-600 rounded w-10 h-10 flex items-center justify-center shrink-0">
            <i class="ph ph-caret-left text-handy-xl"></i>
        </button>
        {{-- Logout Button (on warehouse screen) --}}
        <button x-show="currentScreen === 'warehouse' && isAuthenticated"
                @click="logout()"
                class="p-1 active:bg-blue-600 rounded w-10 h-10 flex items-center justify-center shrink-0"
                title="ログアウト">
            <i class="ph ph-sign-out text-handy-xl"></i>
        </button>
        <h1 class="text-handy-base font-bold truncate" x-text="getHeaderTitle()"></h1>
    </div>
</header>
