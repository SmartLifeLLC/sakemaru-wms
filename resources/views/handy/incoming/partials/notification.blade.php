{{-- Notification Toast - 480x800 optimized --}}
<div x-show="notification.show"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-full"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-full"
     :class="{
         'bg-gray-800': notification.type === 'info',
         'bg-green-600': notification.type === 'success',
         'bg-red-600': notification.type === 'error'
     }"
     class="absolute bottom-3 left-3 right-3 text-white px-3 py-2 rounded-lg shadow-lg flex items-center gap-2 z-50">
    <i :class="{
           'ph-info': notification.type === 'info',
           'ph-check-circle': notification.type === 'success',
           'ph-warning-circle': notification.type === 'error'
       }"
       class="ph text-handy-lg"></i>
    <span x-text="notification.message" class="font-bold text-handy-sm"></span>
</div>
