<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\AgingStokTable;
use App\Filament\Widgets\StaffStatsOverview;
use App\Filament\Widgets\StokBarChart;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Enums\ThemeMode;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')

            // BRAND
            ->brandName('Smart Inventory System PT. JAU')
            ->brandLogo(fn () => view('filament.brand.logo'))
            ->favicon(asset('favicon.ico'))

            // THEME & WARNA
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode()
            ->defaultThemeMode(ThemeMode::System)
            ->colors([
                'primary' => Color::Indigo, // ganti warna disini
            ])
            ->font('Plus Jakarta Sans')

            // LAYOUT
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups(true)

            // GLOBAL SEARCH
            ->globalSearch(true)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])

            // NOTIFIKASI
            ->login()
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')

            // RESOURCES, PAGES, WIDGETS
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                StaffStatsOverview::class,
                StokBarChart::class,
                AgingStokTable::class,
            ])

            // MIDDLEWARE
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}