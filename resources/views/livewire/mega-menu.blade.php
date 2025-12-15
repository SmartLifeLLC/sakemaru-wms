<div x-data="{ openTab: null }" class="w-full bg-white border-b border-gray-200 relative z-50">
    <!-- Load FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <!-- Top Level Tabs (常に表示) -->
    <div class="flex items-center h-16 px-4 max-w-7xl mx-auto relative z-20 bg-white">
        <!-- Logo Area -->
        <div class="mr-8 flex-shrink-0 flex items-center gap-2">
            <img src="/images/logo.png" alt="酒丸蔵" class="h-8">
        </div>

        <!-- Tabs -->
        <nav class="flex space-x-1 h-full">
            @foreach($menuStructure as $tab)
                <div class="h-full flex items-center px-1"
                     @mouseenter="openTab = '{{ $tab['id'] }}'"
                     @mouseleave="openTab = null">

                    <button type="button"
                            class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50 rounded-md flex items-center gap-2 transition-colors"
                            :class="{ 'text-primary-600 bg-gray-50': openTab === '{{ $tab['id'] }}' }">
                        <i class="fa-solid {{ $tab['icon'] }}"></i>
                        <span>{{ $tab['label'] }}</span>
                        <i class="fa-solid fa-chevron-down text-[10px] opacity-50"></i>
                    </button>
                </div>
            @endforeach
        </nav>

        <!-- Right Side: Filament Components -->
        <div class="ml-auto flex items-center gap-4">
            @if (filament()->isGlobalSearchEnabled())
                <div class="mr-4">
                    @livewire(filament()->getGlobalSearchLivewireComponent())
                </div>
            @endif

            @if (filament()->hasDatabaseNotifications())
                @livewire(filament()->getDatabaseNotificationsLivewireComponent())
            @endif

            <x-filament-panels::theme-switcher />

            <x-filament-panels::user-menu />
        </div>
    </div>

    <!-- Mega Menu Dropdowns (ナビバーの下に表示) -->
    @foreach($menuStructure as $tab)
        <div x-show="openTab === '{{ $tab['id'] }}'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="absolute left-0 w-full z-10 bg-white shadow-xl border-t border-gray-100"
             style="top: 64px;"
             @mouseenter="openTab = '{{ $tab['id'] }}'"
             @mouseleave="openTab = null"
             x-cloak>

            <div class="max-w-7xl mx-auto p-6 grid grid-cols-2 gap-6">
                @foreach($tab['groups'] as $group)
                    <div class="space-y-3">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2 pb-2 border-b border-gray-100">
                            {{ $group['label'] }}
                        </h3>

                        <ul class="space-y-1">
                            @foreach($group['items'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}"
                                       class="group flex items-center gap-3 px-2 py-2 text-sm text-gray-700 rounded-md hover:bg-primary-50 hover:text-primary-700 transition-colors {{ $item['isActive'] ? 'bg-primary-50 text-primary-700 font-medium' : '' }}">
                                        @if($item['icon'])
                                            <x-filament::icon
                                                :icon="$item['icon']"
                                                class="w-4 h-4 text-gray-400 group-hover:text-primary-500"
                                            />
                                        @else
                                            <i class="fa-regular fa-circle w-4 h-4 text-[6px] flex items-center justify-center text-gray-300 group-hover:text-primary-400"></i>
                                        @endif

                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <!-- Footer of Dropdown -->
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-100">
                <div class="max-w-7xl mx-auto">
                    <a href="/admin" class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1">
                        <i class="fa-solid fa-arrow-right"></i>
                        <span>{{ $tab['label'] }}の全項目を表示</span>
                    </a>
                </div>
            </div>
        </div>
    @endforeach
</div>
