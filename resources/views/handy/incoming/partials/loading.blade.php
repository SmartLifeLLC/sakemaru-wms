{{-- Loading Overlay - 480x800 optimized --}}
<div x-show="isLoading"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="absolute inset-0 bg-white/80 flex items-center justify-center z-20">
    <div class="flex flex-col items-center">
        <div class="animate-spin rounded-full h-10 w-10 border-4 border-blue-500 border-t-transparent"></div>
        <p class="mt-2 text-gray-600 font-bold text-handy-sm" x-text="loadingMessage || '読み込み中...'"></p>
    </div>
</div>
