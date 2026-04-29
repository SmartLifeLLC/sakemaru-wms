@php
    use Filament\Support\Enums\Width;
    use Filament\Support\Facades\Filament;
    use Filament\View\PanelsRenderHook;

    $panel = filament()->getCurrentPanel();
    $requestPath = trim(request()->path(), '/');

    if ($panel === null) {
        $candidatePanel = Filament::getPanel('admin');
        $candidatePath = trim($candidatePanel?->getPath() ?? '', '/');

        if (
            $candidatePanel !== null
            && $candidatePath !== ''
            && ($requestPath === $candidatePath || str_starts_with($requestPath, "{$candidatePath}/"))
        ) {
            Filament::setCurrentPanel($candidatePanel);
            $panel = $candidatePanel;
        }
    }

    $isFilamentPanel = $panel !== null;
@endphp

@if (! $isFilamentPanel)
    <!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>403 Forbidden</title>
            <style>
                body {
                    margin: 0;
                    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    background: #f8fafc;
                    color: #0f172a;
                }

                .forbidden-shell {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                }

                .forbidden-card {
                    width: 100%;
                    max-width: 640px;
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 16px;
                    padding: 32px 24px;
                    text-align: center;
                    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
                }

                .forbidden-icon {
                    margin: 0 auto 16px;
                    width: 64px;
                    height: 64px;
                    border-radius: 9999px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #fef2f2;
                    color: #dc2626;
                }

                .forbidden-title {
                    margin: 0 0 8px;
                    font-size: 24px;
                    font-weight: 700;
                }

                .forbidden-message {
                    margin: 0;
                    font-size: 14px;
                    color: #475569;
                }
            </style>
        </head>
        <body>
            <div class="forbidden-shell">
                <div class="forbidden-card">
                    <div class="forbidden-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width: 36px; height: 36px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-7.5 13A2 2 0 004.5 20h15a2 2 0 001.71-3.14l-7.5-13a2 2 0 00-3.42 0z" />
                        </svg>
                    </div>
                    <h1 class="forbidden-title">403 Forbidden</h1>
                    <p class="forbidden-message">該当ページへのアクセスが制限されています。</p>
                </div>
            </div>
        </body>
    </html>
@else
    @php
        $livewire = null;
        $hasTopbar = filament()->hasTopbar();
        $hasTopNavigation = filament()->hasTopNavigation();
        $renderHookScopes = $livewire?->getRenderHookScopes();
        $maxContentWidth = filament()->getMaxContentWidth() ?? Width::SevenExtraLarge;

        if (is_string($maxContentWidth)) {
            $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
        }
    @endphp

    <x-filament-panels::layout.base
        :livewire="$livewire"
        @class([
            'fi-body-has-topbar' => $hasTopbar,
            'fi-body-has-top-navigation' => $hasTopNavigation,
        ])
    >
        @if ($hasTopbar)
            {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

            @livewire('mega-menu')

            {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
        @endif

        <div class="fi-layout">
            {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::LAYOUT_START, scopes: $renderHookScopes) }}

            <div
                class="fi-main-ctn"
            >
                {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::CONTENT_BEFORE, scopes: $renderHookScopes) }}

                <main
                    @class([
                        'fi-main',
                        ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                    ])
                >
                    {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                    <div class="fi-page">
                        <div class="fi-page-main">
                            <div class="fi-page-content">
                                <div class="mx-auto flex max-w-4xl flex-col gap-8 py-14 lg:py-20">
                                    <div class="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-red-50 text-red-600 ring-1 ring-red-200 dark:bg-red-950 dark:text-red-300 dark:ring-red-800">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-14 w-14" />
                                    </div>

                                    <x-filament::section class="px-6 py-8 lg:px-10 lg:py-12">
                                        <div class="text-center">
                                            <h1 class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white lg:text-5xl">403 Forbidden</h1>
                                            <p class="mt-4 text-base leading-7 text-gray-600 dark:text-gray-300 lg:text-xl">該当ページへのアクセスが制限されています。</p>
                                        </div>
                                    </x-filament::section>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                </main>

                {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::CONTENT_AFTER, scopes: $renderHookScopes) }}
                {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
            </div>

            {{ \Filament\Support\Facades\FilamentView::renderHook(PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}
        </div>
    </x-filament-panels::layout.base>
@endif
