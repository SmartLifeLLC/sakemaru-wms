@php
    use Filament\Support\Enums\Width;
    use Filament\Facades\Filament;
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $statusCode ??= 500;
    $title ??= sprintf('%d %s', $statusCode, \Symfony\Component\HttpFoundation\Response::$statusTexts[$statusCode] ?? 'Error');
    $message ??= 'ページの表示中に問題が発生しました。';
    $panelCandidates ??= ['admin'];
    $megaMenuPanelIds ??= ['admin'];

    $panel = filament()->getCurrentPanel();
    $requestPath = trim(request()->path(), '/');

    if ($panel === null) {
        foreach ($panelCandidates as $candidatePanelId) {
            $candidatePanel = Filament::getPanel($candidatePanelId);

            if ($candidatePanel === null) {
                continue;
            }

            $candidatePath = trim($candidatePanel->getPath(), '/');

            if (
                $candidatePath !== ''
                && ($requestPath === $candidatePath || str_starts_with($requestPath, "{$candidatePath}/"))
            ) {
                Filament::setCurrentPanel($candidatePanel);
                $panel = $candidatePanel;

                break;
            }
        }
    }

    $canRenderPanelLayout = $panel !== null && filament()->auth()->check();
    $authUser = $canRenderPanelLayout ? filament()->auth()->user() : auth()->user();
    $loginUserName = (string) ($authUser?->name ?? '');
    $loginUserEmail = (string) ($authUser?->email ?? '');
    $canSubmitInquiry = filled(route('admin.error-inquiries.store'));
    $inquiryRoute = route('admin.error-inquiries.store');
@endphp

@push('styles')
    @livewireStyles
@endpush

@push('scripts')
    @livewireScripts
@endpush

@if (! $canRenderPanelLayout)
    <!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{{ $title }}</title>
            <style>
                body {
                    margin: 0;
                    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    background: #f8fafc;
                    color: #0f172a;
                }

                .status-shell {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                }

                .status-card {
                    width: 100%;
                    max-width: 720px;
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 18px;
                    padding: 40px 28px;
                    text-align: center;
                    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
                }

                .status-icon {
                    margin: 0 auto 20px;
                    width: 88px;
                    height: 88px;
                    border-radius: 9999px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #fef2f2;
                    color: #dc2626;
                }

                .status-title {
                    margin: 0 0 12px;
                    font-size: 40px;
                    font-weight: 700;
                    letter-spacing: -0.03em;
                }

            </style>
        </head>
        <body>
            <div class="status-shell">
                @include('errors.partials.status-content', [
                    'statusCode' => $statusCode,
                    'title' => $title,
                    'message' => $message,
                    'homeUrl' => url('/'),
                    'inquiryRoute' => $inquiryRoute,
                    'loginUserName' => $loginUserName,
                    'loginUserEmail' => $loginUserEmail,
                    'canSubmitInquiry' => $canSubmitInquiry,
                ])
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
        $panelId = $panel?->getId();
        $shouldUseMegaMenu = in_array($panelId, $megaMenuPanelIds, true);

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
            <div>
                {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

                @if ($shouldUseMegaMenu)
                    @livewire('mega-menu')
                @else
                    @livewire(filament()->getTopbarLivewireComponent())
                @endif

                {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
            </div>
        @endif

        <div class="fi-layout">
            {{ FilamentView::renderHook(PanelsRenderHook::LAYOUT_START, scopes: $renderHookScopes) }}

            <div class="fi-main-ctn">
                {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_BEFORE, scopes: $renderHookScopes) }}

                <main
                    @class([
                        'fi-main',
                        ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                    ])
                >
                    {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                    <div class="fi-simple-layout">
                        <div class="fi-simple-main-ctn py-10 lg:py-16">
                            @include('errors.partials.status-content', [
                                'statusCode' => $statusCode,
                                'title' => $title,
                                'message' => $message,
                                'homeUrl' => url('/admin'),
                                'inquiryRoute' => $inquiryRoute,
                                'loginUserName' => $loginUserName,
                                'loginUserEmail' => $loginUserEmail,
                                'canSubmitInquiry' => $canSubmitInquiry,
                            ])
                        </div>
                    </div>

                    {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                </main>

                {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_AFTER, scopes: $renderHookScopes) }}
                {{ FilamentView::renderHook(PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
            </div>

            {{ FilamentView::renderHook(PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}
        </div>
    </x-filament-panels::layout.base>
@endif
