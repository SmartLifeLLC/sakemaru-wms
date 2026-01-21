@extends('handy.layouts.app')

@section('title', 'HANDY 入庫処理システム')

@section('content')
<!-- Alpine.js App Container - 480x800 WVGA optimized -->
<div x-data="handyIncomingApp()" x-init="init()"
     class="handy-container bg-white flex flex-col relative overflow-hidden">

    {{-- ================= HEADER ================= --}}
    @include('handy.incoming.partials.header')

    {{-- ================= MAIN CONTENT AREA ================= --}}
    <main class="handy-main overflow-y-auto no-scrollbar bg-slate-50 relative">

        {{-- Loading Overlay --}}
        @include('handy.incoming.partials.loading')

        {{-- 0. Login Screen --}}
        @include('handy.incoming.partials.login')

        {{-- 1. Warehouse Selection Screen --}}
        @include('handy.incoming.partials.warehouse-select')

        {{-- 2. Product List Screen --}}
        @include('handy.incoming.partials.product-list')

        {{-- 3. Process Screen --}}
        @include('handy.incoming.partials.process')

        {{-- 4. Result Screen --}}
        @include('handy.incoming.partials.result')

        {{-- 5. History Screen --}}
        @include('handy.incoming.partials.history')

    </main>

    {{-- Fixed Footer with Function Keys --}}
    @include('handy.incoming.partials.footer')

    {{-- Notification Toast --}}
    @include('handy.incoming.partials.notification')

</div>

{{-- API Configuration --}}
<script>
    window.HANDY_CONFIG = {
        apiKey: '{{ $apiKey }}',
        baseUrl: '{{ url('/api') }}',
        authKey: {!! $authKey ? "'" . e($authKey) . "'" : 'null' !!},
        warehouseId: {!! $warehouseId ? (int)$warehouseId : 'null' !!}
    };
</script>
@endsection
