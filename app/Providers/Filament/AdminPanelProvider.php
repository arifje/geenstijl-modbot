<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Notifications\LoginCode;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    private static function themeTags(): string
    {
        $version = @filemtime(public_path('css/modbot-theme.css')) ?: 1;

        return '<link rel="preconnect" href="https://fonts.bunny.net">'
            .'<link rel="stylesheet" href="https://fonts.bunny.net/css?family=anton:400&display=swap">'
            .'<link rel="stylesheet" href="'.e(asset('css/modbot-theme.css')).'?v='.$version.'">';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Password + a code sent by email, required for every user.
            ->multiFactorAuthentication([
                EmailAuthentication::make()
                    ->codeExpiryMinutes(10)
                    ->codeNotification(LoginCode::class),
            ], isRequired: true)
            // Keep the admin out of search engines (see also the NoIndex middleware).
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => '<meta name="robots" content="noindex, nofollow">')
            ->brandName('ModBot')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.4rem')
            ->favicon(asset('favicon.svg'))
            ->colors([
                // GeenStijl pink; black and white are the secondary colours.
                'primary' => Color::hex('#ff33a9'),
                'gray' => Color::Zinc,
            ])
            // GeenStijl-style theme on top of Filament (public/css/modbot-theme.css).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => self::themeTags())
            ->navigationGroups(['Moderation', 'Configuration', 'System'])
            // Use the full width, so content starts at the left instead of centred.
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
