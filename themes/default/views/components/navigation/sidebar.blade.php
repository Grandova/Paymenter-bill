<aside id="main-aside" class="client-sidebar hidden lg:flex">
    <x-navigation.sidebar-links />
    <a href="{{ route('account') }}" wire:navigate class="client-nav-link border-t border-neutral m-4 mt-auto pt-5">
        <x-ri-user-line class="size-5 text-muted" />{{ __('navigation.personal_details') }}
    </a>
</aside>
