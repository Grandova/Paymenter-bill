{{-- @deprecated, removed in 1.6 --}}
<div>
    <strong class="block p-2 text-xs font-semibold uppercase text-base/50"> {{ __('Currency') }} </strong>
    <x-select wire:model.live="currentCurrency" :options="$this->currencies" placeholder="{{ __('Select currency') }}" />
</div>