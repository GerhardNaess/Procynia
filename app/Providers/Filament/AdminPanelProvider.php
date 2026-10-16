<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\LocalInitialsAvatarProvider;
use App\Http\Middleware\SetCustomerLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            // Local SVG initials instead of Filament's ui-avatars.com default: that sent internal
            // user names to a third party on every admin page load, and was the only reason the CSP
            // had to allow an external image origin (findings F-05 / closure round).
            ->defaultAvatarProvider(LocalInitialsAvatarProvider::class)
            ->brandName('Procynia')
            ->login()
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Amber, with shade 600 taken one step darker (amber-700). Filament and the custom admin
            // views use primary-600 for link text, selected-option badges and filled buttons with
            // white text; amber-600 gives only about 3:1 against white and amber-50 (WCAG 1.4.3,
            // A11Y-055). Amber-700 gives about 5:1. Every other shade is unchanged.
            ->colors([
                'primary' => array_replace(Color::Amber, [600 => Color::Amber[700]]),
            ])
            // After a rejected submit, move focus to the first invalid field (A11Y-212).
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.components.focus-first-error')->render())
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
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetCustomerLocale::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
