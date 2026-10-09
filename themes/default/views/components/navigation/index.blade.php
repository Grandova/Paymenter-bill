<nav class="client-topbar" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
    <div class="client-brand">
        <button @click="menuOpen = !menuOpen" class="client-menu-button" aria-label="{{ __('Toggle Menu') }}" :aria-expanded="menuOpen">
            <x-ri-menu-line class="size-5" />
        </button>
        <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" wire:navigate class="flex items-center gap-2 min-w-0">
            <x-logo class="h-8" />
            @if(theme('logo_display', 'logo-and-name') != 'logo-only')
            <span class="text-lg font-bold truncate">{{ config('app.name') }}</span>
            @endif
        </a>
    </div>
    <div class="flex items-center justify-end gap-2 sm:gap-3 min-w-0">
        <div class="hidden md:block client-order-menu">
            <x-dropdown :showArrow="false" width="w-56">
                <x-slot:trigger>
                    <x-ri-add-circle-line class="size-4 mr-2" />{{ __('Order new service') }}
                </x-slot:trigger>
                <x-slot:content>
                    @foreach (\App\Classes\Navigation::getLinks() as $nav)
                        @if (!empty($nav['children']))
                            @foreach ($nav['children'] as $child)
                                <x-navigation.link :href="$child['url']" :spa="$child['spa'] ?? true">{{ $child['name'] }}</x-navigation.link>
                            @endforeach
                        @endif
                    @endforeach
                    <x-navigation.link :href="route('home')">{{ __('View all products') }}</x-navigation.link>
                </x-slot:content>
            </x-dropdown>
        </div>
        @if(auth()->check() && config('settings.credits_enabled'))
            @php
                $currency = \App\Models\Currency::find(session('currency', config('settings.default_currency')));
                $credit = auth()->user()->credits()->where('currency_code', $currency->code)->first();
            @endphp
            <a href="{{ route('account.credits') }}" wire:navigate class="client-credit hidden lg:flex">
                <x-ri-wallet-3-line class="size-4" />{{ __('account.credits') }}
                <strong>{{ $credit?->formattedAmount ?? new \App\Classes\Price(['price' => 0, 'currency' => $currency]) }}</strong>
            </a>
        @endif
        <livewire:components.cart />
        @if(auth()->check())
            <livewire:components.notifications />
            <x-dropdown :showArrow="false" width="w-64">
                <x-slot:trigger>
                    <img src="{{ auth()->user()->avatar }}" class="size-8 rounded-full" alt="{{ __('Open user menu') }}" />
                    <span class="hidden xl:flex flex-col text-left ml-3 max-w-40">
                        <span class="truncate">{{ auth()->user()->name }}</span>
                        <span class="truncate text-xs font-normal text-muted">{{ auth()->user()->email }}</span>
                    </span>
                </x-slot:trigger>
                <x-slot:content>
                    @foreach (\App\Classes\Navigation::getAccountDropdownLinks() as $nav)
                        <x-navigation.link :href="$nav['url']" :spa="$nav['spa'] ?? true">{{ $nav['name'] }}</x-navigation.link>
                    @endforeach
                    <livewire:components.locale-switch />
                    <livewire:auth.logout />
                </x-slot:content>
            </x-dropdown>
        @else
            <a href="{{ route('login') }}" wire:navigate class="text-sm px-3">{{ __('navigation.login') }}</a>
            @if(!config('settings.registration_disabled', false))
            <a href="{{ route('register') }}" wire:navigate class="hidden sm:block"><x-button.primary>{{ __('navigation.register') }}</x-button.primary></a>
            @endif
            <div class="hidden lg:block"><livewire:components.locale-switch /></div>
        @endif
        <div class="border border-neutral rounded-md"><x-theme-toggle /></div>
    </div>
    <template x-teleport="body">
        <div x-show="menuOpen" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="{{ __('Navigation') }}">
            <div class="absolute inset-0 bg-black/30" @click="menuOpen = false"></div>
            <aside class="relative w-72 max-w-[85vw] h-full bg-background-secondary shadow-xl overflow-y-auto" x-trap.inert.noscroll="menuOpen">
                <div class="h-16 flex items-center justify-between px-5 border-b border-neutral">
                    <span class="font-semibold">{{ config('app.name') }}</span>
                    <button @click="menuOpen = false" aria-label="{{ __('Close') }}" class="p-2"><x-ri-close-line class="size-5" /></button>
                </div>
                <x-navigation.sidebar-links />
                @guest
                <div class="px-4"><livewire:components.locale-switch /></div>
                @endguest
            </aside>
        </div>
    </template>
</nav>
