<?php

namespace App\Providers\Filament;

use App\Enums\EMenuCategory;
use Archilex\AdvancedTables\Plugin\AdvancedTablesPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use WatheqAlshowaiter\FilamentStickyTableHeader\StickyTableHeaderPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->topNavigation() // トップナビゲーションを有効化
            ->maxContentWidth('full')
            ->breadcrumbs(false) // パンくずリストを無効化
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->navigationGroups(
                collect(EMenuCategory::cases())
                    ->sortBy(fn (EMenuCategory $category) => $category->sort())
                    ->map(fn (EMenuCategory $category) => NavigationGroup::make($category->label()))
                    ->values()
                    ->toArray()
            )
            ->navigationItems(
                [NavigationItem::make('API Document')
                    ->url('/api/documentation', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-link')->group(EMenuCategory::SETTINGS->label()),
                ]
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                StickyTableHeaderPlugin::make(),
                AdvancedTablesPlugin::make()
                    ->userViewsEnabled(true)
                    ->resourceNavigationGroup(EMenuCategory::SETTINGS->label())
                    ->resourceNavigationSort(1000)
                    ->userView(\App\Models\FilamentFilterSets\UserView::class)
                    ->managedUserView(\App\Models\FilamentFilterSets\ManagedUserView::class)
                    ->managedPresetView(\App\Models\FilamentFilterSets\ManagedPresetView::class)
                    ->managedDefaultView(\App\Models\FilamentFilterSets\ManagedDefaultView::class),
            ]
            )
            ->authMiddleware([
                Authenticate::class,
            ])->authGuard('web');
    }
}
