@extends('handy-v2.layouts.app')

@section('content')
<div x-data="handyV2App()" x-init="init()" class="wms-app">

    {{-- ===== Login Screen ===== --}}
    <template x-if="currentScreen === SCREENS.LOGIN">
        @include('handy-v2.partials.login')
    </template>

    {{-- ===== Authenticated Shell ===== --}}
    <template x-if="currentScreen !== SCREENS.LOGIN">
        <div class="wms-app">
            {{-- Header --}}
            <header class="wms-header">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4" />
                    </svg>
                    <span x-text="warehouseName"></span>
                </div>
                <div class="flex items-center gap-2 text-sm opacity-80">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span x-text="pickerName"></span>
                </div>
            </header>

            {{-- Body: Sidebar + Main --}}
            <div class="wms-body">
                {{-- Side Navigation --}}
                <nav class="wms-side-nav">
                    <button
                        class="wms-side-nav-item"
                        :class="{ 'active': currentTab === TABS.INCOMING }"
                        @click="switchTab(TABS.INCOMING)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-2.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                        <span>入荷</span>
                    </button>

                    <button
                        class="wms-side-nav-item"
                        :class="{ 'active': currentTab === TABS.PICKING }"
                        @click="switchTab(TABS.PICKING)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                        </svg>
                        <span>出荷</span>
                    </button>

                    <button
                        class="wms-side-nav-item"
                        :class="{ 'active': currentTab === TABS.SETTINGS }"
                        @click="switchTab(TABS.SETTINGS)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>設定</span>
                    </button>
                </nav>

                {{-- Main Content --}}
                <main class="wms-main">
                    {{-- Home --}}
                    <template x-if="currentScreen === SCREENS.HOME">
                        @include('handy-v2.partials.home')
                    </template>

                    {{-- Incoming: Schedule List --}}
                    <template x-if="currentScreen === SCREENS.INCOMING_LIST">
                        @include('handy-v2.partials.incoming.list')
                    </template>

                    {{-- Incoming: Work --}}
                    <template x-if="currentScreen === SCREENS.INCOMING_WORK">
                        @include('handy-v2.partials.incoming.work')
                    </template>

                    {{-- Incoming: Result --}}
                    <template x-if="currentScreen === SCREENS.INCOMING_RESULT">
                        @include('handy-v2.partials.incoming.result')
                    </template>

                    {{-- Incoming: History --}}
                    <template x-if="currentScreen === SCREENS.INCOMING_HISTORY">
                        @include('handy-v2.partials.incoming.history')
                    </template>

                    {{-- Picking: Task List --}}
                    <template x-if="currentScreen === SCREENS.PICKING_TASKS">
                        @include('handy-v2.partials.picking.tasks')
                    </template>

                    {{-- Picking: Item --}}
                    <template x-if="currentScreen === SCREENS.PICKING_ITEM">
                        @include('handy-v2.partials.picking.item')
                    </template>

                    {{-- Picking: Complete --}}
                    <template x-if="currentScreen === SCREENS.PICKING_COMPLETE">
                        @include('handy-v2.partials.picking.complete')
                    </template>

                    {{-- Picking: Result --}}
                    <template x-if="currentScreen === SCREENS.PICKING_RESULT">
                        @include('handy-v2.partials.picking.result')
                    </template>

                    {{-- Settings --}}
                    <template x-if="currentScreen === SCREENS.SETTINGS">
                        @include('handy-v2.partials.settings')
                    </template>
                </main>
            </div>
        </div>
    </template>

    {{-- ===== Warehouse Selection Modal ===== --}}
    <template x-if="showWarehouseModal">
        <div class="wms-modal-overlay" @click.self="showWarehouseModal = false">
            <div class="wms-modal">
                <h2 class="text-lg font-bold mb-4 text-center">倉庫を選択</h2>
                <div class="wms-warehouse-grid">
                    <template x-for="wh in warehouse.warehouses" :key="wh.id">
                        <button
                            class="wms-warehouse-item"
                            @click="selectWarehouse(wh)"
                        >
                            <div class="font-bold text-sm" x-text="wh.name"></div>
                            <div class="text-xs text-gray-500 mt-1" x-text="wh.code || ''"></div>
                        </button>
                    </template>
                </div>
                <template x-if="warehouse.warehouses.length === 0">
                    <p class="text-center text-gray-400 py-4 text-sm">倉庫データを読み込み中...</p>
                </template>
            </div>
        </div>
    </template>

    {{-- ===== Toast Notification ===== --}}
    <div
        x-show="notification.show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-[-8px]"
        x-transition:enter-end="opacity-1 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-1"
        x-transition:leave-end="opacity-0"
        class="wms-toast"
        :class="{
            'wms-toast-success': notification.type === 'success',
            'wms-toast-error': notification.type === 'error',
            'wms-toast-warning': notification.type === 'warning',
            'wms-toast-info': notification.type === 'info',
        }"
        @click="notification.dismiss()"
        x-text="notification.message"
        style="display: none;"
    ></div>

    {{-- ===== Loading Overlay ===== --}}
    <div x-show="isLoading" class="wms-loading-overlay" style="display: none;">
        <div class="flex flex-col items-center gap-3">
            <div class="wms-spinner"></div>
            <span class="text-white text-sm">読み込み中...</span>
        </div>
    </div>

</div>
@endsection
