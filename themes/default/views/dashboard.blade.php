<div class="container client-dashboard">
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <a href="{{ route('services', ['status' => 'active']) }}" wire:navigate class="client-stat">
            <div><span>{{ __('dashboard.active_services') }}</span><x-ri-pulse-line class="size-5 text-muted" /></div>
            <strong>{{ Auth::user()->services()->where('status', 'active')->count() }}</strong>
        </a>
        <a href="{{ route('services', ['status' => 'pending']) }}" wire:navigate class="client-stat">
            <div><span>{{ __('Pending services') }}</span><x-ri-time-line class="size-5 text-muted" /></div>
            <strong>{{ Auth::user()->services()->where('status', 'pending')->count() }}</strong>
        </a>
        @if(!config('settings.tickets_disabled', false))
        <a href="{{ route('tickets') }}" wire:navigate class="client-stat">
            <div><span>{{ __('dashboard.open_tickets') }}</span><x-ri-ticket-line class="size-5 text-muted" /></div>
            <strong>{{ Auth::user()->tickets()->where('status', '!=', 'closed')->count() }}</strong>
        </a>
        @endif
        <a href="{{ route('invoices') }}" wire:navigate class="client-stat">
            <div><span>{{ __('dashboard.unpaid_invoices') }}</span><x-ri-bank-card-line class="size-5 text-muted" /></div>
            <strong>{{ Auth::user()->invoices()->where('status', 'pending')->count() }}</strong>
        </a>
    </div>
    <livewire:services.index :dashboard="true" />
    <div class="mt-6">{!! hook('pages.dashboard') !!}</div>
</div>
