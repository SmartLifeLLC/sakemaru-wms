<div x-data="{ openTab: null, menuTimeout: null }" class="relative" @click.outside="openTab = null">
    <!-- Load FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <!-- Upper Utility Bar -->
    <div class="bg-slate-900 text-slate-300 text-xs py-1.5 px-6 hidden md:flex justify-between items-center">
        <div>酒丸蔵（さけまるぐら） - スマート在庫管理システム</div>
        <div class="flex gap-4">
            <a href="#" class="hover:text-white transition-colors">ヘルプセンター</a>
        </div>
    </div>

    <!-- Main Navigation Bar -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-50 shadow-sm">
        <div class="max-w-[1600px] mx-auto px-4 md:px-6">
            <div class="flex items-center justify-between h-16">

                <!-- Logo & Nav -->
                <div class="flex items-center gap-8">
                    <!-- Logo -->
                    <a href="/admin" class="flex items-center">
                        <img src="/images/logo.png" alt="酒丸蔵" class="h-10 w-auto object-contain">
                    </a>

                    <!-- Desktop Nav Items -->
                    <nav class="flex items-center gap-1"
                         @mouseleave="menuTimeout = setTimeout(() => { openTab = null }, 150)">
                        @forelse($menuStructure as $tab)
                            <button
                                type="button"
                                class="flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors duration-200 rounded-md"
                                :class="openTab === '{{ $tab['id'] }}' ? 'text-indigo-600 bg-indigo-50' : 'text-slate-600 hover:text-indigo-600 hover:bg-slate-50'"
                                @mouseenter="if(menuTimeout) clearTimeout(menuTimeout); openTab = '{{ $tab['id'] }}'"
                            >
                                <i class="fa-solid {{ $tab['icon'] }}"></i>
                                <span>{{ $tab['label'] }}</span>
                                <i class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"
                                   :class="openTab === '{{ $tab['id'] }}' ? 'rotate-180' : ''"></i>
                            </button>
                        @empty
                            <span class="text-red-500 text-sm">メニューが空です</span>
                        @endforelse
                    </nav>
                </div>

                <!-- Right Side: Filament Components -->
                <div class="flex items-center gap-3">
                    @if (filament()->isGlobalSearchEnabled())
                        <div class="mr-2">
                            @livewire(filament()->getGlobalSearchLivewireComponent())
                        </div>
                    @endif

                    @if (filament()->hasDatabaseNotifications())
                        @livewire(filament()->getDatabaseNotificationsLivewireComponent())
                    @endif

                    <x-filament-panels::theme-switcher />

                    <div class="h-8 w-px bg-slate-200 mx-1"></div>

                    <x-filament-panels::user-menu />
                </div>
            </div>
        </div>

        <!-- Mega Menu Dropdowns -->
        @foreach($menuStructure as $tab)
            <div
                x-show="openTab === '{{ $tab['id'] }}'"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="fixed left-0 right-0 bg-white border-b border-slate-200 shadow-xl z-40"
                style="top: 64px;"
                @mouseenter="if(menuTimeout) clearTimeout(menuTimeout); openTab = '{{ $tab['id'] }}'"
                @mouseleave="menuTimeout = setTimeout(() => { openTab = null }, 150)"
                x-cloak
            >
                <div class="w-full px-8 py-6">
                    @php
                        $groupCount = count($tab['groups']);
                        $gridClass = match(true) {
                            $groupCount === 1 => 'grid-cols-1 max-w-md',
                            $groupCount === 2 => 'grid-cols-2 max-w-2xl',
                            $groupCount === 3 => 'grid-cols-3 max-w-5xl',
                            $groupCount === 4 => 'grid-cols-4 max-w-6xl',
                            default => 'grid-cols-4 max-w-7xl',
                        };
                    @endphp
                    <div class="grid {{ $gridClass }} gap-8 mx-auto">
                        @foreach($tab['groups'] as $index => $group)
                            <div>
                                <div class="flex flex-col gap-3">
                                    <!-- Group Header -->
                                    <div class="flex items-center gap-2 pb-2 border-b border-slate-100">
                                        @if(isset($group['icon']) && $group['icon'])
                                            <x-filament::icon
                                                :icon="$group['icon']"
                                                class="w-[18px] h-[18px] text-indigo-600"
                                            />
                                        @endif
                                        <h3 class="font-bold text-slate-800 text-sm">{{ $group['label'] }}</h3>
                                    </div>

                                    <!-- Menu Items -->
                                    <ul class="flex flex-col gap-1">
                                        @foreach($group['items'] as $item)
                                            <li>
                                                <a href="{{ $item['url'] }}"
                                                   class="group flex items-start gap-3 p-2 rounded-lg hover:bg-indigo-50 transition-all duration-200 {{ $item['isActive'] ? 'bg-indigo-50' : '' }}">
                                                    <div class="mt-1 p-1.5 rounded-md bg-white border border-slate-200 text-slate-500 group-hover:text-indigo-600 group-hover:border-indigo-200 transition-colors shadow-sm {{ $item['isActive'] ? 'text-indigo-600 border-indigo-200' : '' }}">
                                                        @if(isset($item['icon']) && $item['icon'])
                                                            <x-filament::icon
                                                                :icon="$item['icon']"
                                                                class="w-4 h-4"
                                                            />
                                                        @else
                                                            <i class="fa-solid fa-circle text-[6px] w-4 h-4 flex items-center justify-center"></i>
                                                        @endif
                                                    </div>
                                                    <div>
                                                        <div class="text-sm font-medium text-slate-700 group-hover:text-indigo-700 {{ $item['isActive'] ? 'text-indigo-700 font-semibold' : '' }}">
                                                            {{ $item['label'] }}
                                                        </div>
                                                    </div>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>
        @endforeach
    </header>
</div>
