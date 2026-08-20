<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\AttendanceOverview;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\SetLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
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
            ->login()
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
                AttendanceOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                // After AuthenticateSession (needs $request->user()) and
                // StartSession (needs $request->session()) — Filament panel
                // routes don't go through Laravel's `web` middleware group,
                // so the global SetLocale registration in bootstrap/app.php
                // never reaches /admin/* without this.
                SetLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->userMenuItems([
                // Shows only "switch to the other language" — the active
                // one hides itself, so there's never a redundant "you are
                // here" item for a 2-locale toggle.
                Action::make('locale-fr')
                    ->label('Français')
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn () => route('locale.switch', 'fr'))
                    ->visible(fn () => app()->getLocale() !== 'fr'),
                Action::make('locale-en')
                    ->label('English')
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn () => route('locale.switch', 'en'))
                    ->visible(fn () => app()->getLocale() !== 'en'),
            ]);
    }
}
