<footer class="client-footer">
    @if(auth()->check() && !config('settings.tickets_disabled', false))
    <a href="{{ route('tickets') }}" wire:navigate>{{ __('Support tickets') }}<x-ri-arrow-right-up-line class="size-4" /></a>
    @endif
</footer>
