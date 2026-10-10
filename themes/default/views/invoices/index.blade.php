<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.input name="search" wire:model.live.debounce.300ms="search" :placeholder="__('invoices.search_placeholder')" :aria-label="__('invoices.search_placeholder')" />
        <x-form.select name="status" wire:model.live="status" :aria-label="__('invoices.status')">
            <option value="">{{ __('All statuses') }}</option>
            <option value="pending">{{ __('invoices.payment_pending') }}</option>
            <option value="paid">{{ __('invoices.paid') }}</option>
            <option value="cancelled">{{ __('Cancelled') }}</option>
        </x-form.select>
    </div>

    @forelse ($invoices as $invoice)
    <a href="{{ route('invoices.show', $invoice) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <div class="flex items-center gap-3">
            <div class="bg-secondary/10 p-2 rounded-lg">
                <x-ri-bill-line class="size-5 text-secondary" />
            </div>
            <span class="font-medium">{{ !$invoice->number && config('settings.invoice_proforma', false) ? __('invoices.proforma_invoice', ['id' => $invoice->id]) : __('invoices.invoice', ['id' => $invoice->number]) }}</span>
            <span class="text-base/50 font-semibold">
                <x-ri-circle-fill class="size-1 text-base/20" />
            </span>
            <span class="text-base text-sm">{{ $invoice->formattedTotal }}</span>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium
                @if ($invoice->status == 'paid') text-success bg-success/20
                @elseif($invoice->status == 'cancelled') text-info bg-info/20
                @else text-warning bg-warning/20
                @endif">
                @if ($invoice->status == 'paid')
                    <x-ri-checkbox-circle-fill class="size-4 shrink-0" />
                    {{ __('invoices.paid') }}
                @elseif($invoice->status == 'cancelled')
                    <x-ri-forbid-fill class="size-4 shrink-0" />
                    {{ __('Cancelled') }}
                @elseif($invoice->status == 'pending')
                    <x-ri-error-warning-fill class="size-4 shrink-0" />
                    {{ __('invoices.payment_pending') }}
                @endif
            </span>
        </div>
        <p class="text-base text-sm text-base/60">{{ __('invoices.invoice_date') }}：{{ $invoice->created_at->translatedFormat(__('general.date_format')) }}</p>
        @foreach ($invoice->items as $item)
            <p class="text-base text-sm">{{ __('Item(s):') }} {{ $item->description }}</p>
        @endforeach
        </div>
    </a>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('invoices.no_invoices') }}</p>
    </div>
    @endforelse

    {{ $invoices->links() }}
</div>
