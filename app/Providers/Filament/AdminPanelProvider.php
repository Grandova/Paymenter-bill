<?php

namespace App\Providers\Filament;

use App\Actions\Auth\Logout;
use App\Http\Middleware\ImpersonateMiddleware;
use App\Http\Middleware\LockSession;
use App\Http\Middleware\ResolveUserSession;
use App\Http\Middleware\SetLocale;
use App\Models\Extension;
use App\Providers\SettingsProvider;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\Entry;
use Filament\Notifications\Livewire\Notifications;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\View\PanelsIconAlias;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Filament loads before the settings provider, so we need to load the settings here
        SettingsProvider::getSettings();

        Notifications::alignment(Alignment::Center);

        Field::configureUsing(fn (Field $field) => $field->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        Entry::configureUsing(fn (Entry $entry) => $entry->translateLabel());
        Action::configureUsing(fn (Action $action) => $action->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());

        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->spa()
            ->colors([
                'primary' => Color::Zinc,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->favicon(config('settings.favicon') ? Storage::url(config('settings.favicon')) : null)
            ->brandLogo(config('settings.logo') ? Storage::url(config('settings.logo')) : null)
            ->darkModeBrandLogo(config('settings.logo_dark') ? Storage::url(config('settings.logo_dark')) : null)
            ->brandName(config('settings.logo') || config('settings.logo_dark') ? null : config('app.name'))
            ->brandLogoHeight('2rem')
            ->discoverResources(in: app_path('Admin/Resources'), for: 'App\\Admin\\Resources')
            ->discoverPages(in: app_path('Admin/Pages'), for: 'App\\Admin\\Pages')
            ->discoverClusters(in: app_path('Admin/Clusters'), for: 'App\\Admin\\Clusters')
            ->userMenuItems([
                'exit_admin' => Action::make('exit_admin')
                    ->label(__('Exit Admin'))
                    ->url('/')
                    ->icon('heroicon-s-arrow-uturn-left'),
                'logout' => Action::make('logout')
                    ->label(__('Sign out'))
                    ->icon(FilamentIcon::resolve(PanelsIconAlias::USER_MENU_LOGOUT_BUTTON) ?? Heroicon::ArrowLeftOnRectangle)
                    ->action(function (Logout $logoutAction) {
                        $logoutAction->execute();

                        return redirect('/');
                    }),
            ])
            ->discoverWidgets(in: app_path('Admin/Widgets'), for: 'App\\Admin\\Widgets')
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => Blade::render('<x-admin-footer />'),
            )
            ->sidebarWidth('15rem')
            ->navigationGroups([
                __('Products and services'),
                __('Customers and support'),
                __('Finance'),
                __('Site content'),
                __('Configuration'),
                __('Extensions'),
                __('System'),
                __('Other'),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                ResolveUserSession::class,
                LockSession::class,
                ImpersonateMiddleware::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css', 'default')
            ->authMiddleware([
                Authenticate::class,
            ]);

        try {
            foreach (collect(Extension::where(function ($query) {
                $query->where('enabled', true)->orWhere('type', 'server')->orWhere('type', 'gateway');
            })->get())->unique('extension') as $extension) {
                $panel->discoverResources(in: base_path('extensions' . '/' . $extension->path . '/Admin/Resources'), for: $extension->namespace . '\\Admin\\Resources');
                $panel->discoverPages(in: base_path('extensions' . '/' . $extension->path . '/Admin/Pages'), for: $extension->namespace . '\\Admin\\Pages');
                $panel->discoverClusters(in: base_path('extensions' . '/' . $extension->path . '/Admin/Clusters'), for: $extension->namespace . '\\Admin\\Clusters');
            }
        } catch (Exception $e) {
            // Do nothing
        }

        return $panel;
    }
}
