<div class="container">
    <div class="mb-6">
        <h1 class="text-3xl font-bold">{{ __('Review order') }}</h1>
        <p class="text-muted mt-2 text-sm">{{ __('Review your services and billing period before checkout.') }}</p>
    </div>
    <div class="grid xl:grid-cols-3 gap-6 items-start">
        <div class="flex flex-col xl:col-span-2 gap-4 min-w-0">
            @if (Cart::items()->count() === 0)
            <h1 class="text-xl font-semibold">
                {{ __('product.empty_cart') }}
            </h1>
            @endif
            @foreach (Cart::items() as $item)
            <div class="client-panel flex flex-col sm:flex-row justify-between gap-5 w-full">
                <div class="flex flex-col gap-1">
                    <h2 class="text-xl font-semibold">
                        {{ $item->product->name }}
                    </h2>
                    <p class="text-sm text-muted">{{ $item->plan->name }}</p>
                    @if ($item->plan->type === 'recurring')
                    @php
                        $renewalPrice = $item->price->price;
                        if ($item->cart->coupon && ($item->cart->coupon->recurring === null || (int) $item->cart->coupon->recurring === 1)) {
                            $renewalPrice = $item->price->original_price;
                        }
                    @endphp
                    <p class="text-sm text-muted">
                        {{ __('services.price_every_period', [
                            'price' => $item->price->format($renewalPrice * $item->quantity),
                            'period' => $item->plan->billing_period > 1 ? $item->plan->billing_period : '',
                            'unit' => strtolower(trans_choice(__('services.billing_cycles.' . $item->plan->billing_unit), $item->plan->billing_period)),
                        ]) }}
                    </p>
                    @elseif ($item->plan->type === 'one-time')
                    <p class="text-sm text-muted">{{ __('services.price_one_time', ['price' => $item->price->format($item->price->price * $item->quantity)]) }}</p>
                    @endif
                    @if ($item->price->has_setup_fee)
                    <p class="text-sm text-muted">+ {{ $item->price->format($item->price->setup_fee * $item->quantity) }} {{ __('product.setup_fee') }}</p>
                    @endif
                    <p class="text-sm text-muted">
                        @foreach ($item->config_options as $option)
                        {{ $option['option_name'] }}: {{ $option['value_name'] }}<br>
                        @endforeach
                    </p>
                </div>
                <div class="flex flex-col justify-between sm:items-end gap-4">
                    <h3 class="text-xl font-semibold p-1">
                        {{ $item->price->format($item->price->total * $item->quantity) }} @if ($item->quantity > 1)
                        ({{ __('Price per item') }} {{ $item->price }})
                        @endif
                    </h3>
                    <div class="flex flex-row gap-2">
                        @if ($item->product->allow_quantity == 'combined')
                        <div class="flex flex-row gap-1 items-center mr-4">
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                class="h-full !w-fit">
                                -
                            </x-button.secondary>
                            <x-form.input class="h-10 text-center" disabled divClass="!mt-0 !w-14" value="{{ $item->quantity }}" name="quantity" />
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }});"
                                class="h-full !w-fit">
                                +
                            </x-button.secondary>
                        </div>
                        @endif
                        <a href="{{ route('products.checkout', [$item->product->category, $item->product, 'edit' => $item->id]) }}"
                            wire:navigate>
                            <x-button.primary class="h-fit w-fit">
                                {{ __('product.edit') }}
                            </x-button.primary>
                        </a>
                        <x-button.danger wire:click="removeProduct({{ $item->id }})" class="h-fit !w-fit">
                            <x-loading target="removeProduct({{ $item->id }})" />
                            <div wire:loading.remove wire:target="removeProduct({{ $item->id }})">
                                {{ __('product.remove') }}
                            </div>
                        </x-button.danger>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        <div class="flex flex-col gap-4 xl:sticky xl:top-24 min-w-0">
            @if (Cart::items()->count() > 0)
            <div class="client-panel flex flex-col gap-5 w-full">
                <h2 class="text-xl font-semibold mb-3">
                    {{ __('product.order_summary') }}
                </h2>
                <div class="font-semibold flex items-end gap-2">
                    @if(!$coupon)
                    <x-form.input wire:model="coupon" name="coupon" label="{{ __('Coupon') }}" />
                    <x-button.primary wire:click="applyCoupon" class="h-fit !w-fit mb-0.5" wire:loading.attr="disabled">
                        <x-loading target="applyCoupon" />
                        <div wire:loading.remove wire:target="applyCoupon">
                            {{ __('product.apply') }}
                        </div>
                    </x-button.primary>
                    @else
                    <div class="flex justify-between items-center w-full">
                        <h4 class="text-center w-full">{{ $coupon->code }}</h4>
                        <x-button.secondary wire:click="removeCoupon" class="h-fit !w-fit">
                            {{ __('product.remove') }}
                        </x-button.secondary>
                    </div>
                    @endif
                </div>
                <div class="font-semibold flex justify-between">
                    <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
                </div>
                @if ($total->tax > 0)
                <div class="font-semibold flex justify-between">
                    <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->format($total->tax) }}
                </div>
                @endif
                <div class="text-lg font-semibold flex justify-between mt-1">
                    <h4>{{ __('invoices.total') }}:</h4> {{ $total->format($total->total) }}
                </div>

                <div class="flex flex-col gap-2 w-full col-span-1">
                    @if(config('settings.tos'))
                    <x-form.checkbox wire:model="tos" name="tos">
                        {{ __('product.tos') }}
                        <a href="{{ config('settings.tos') }}" target="_blank" class="text-primary hover:text-primary/80">
                            {{ __('product.tos_link') }}
                        </a>
                    </x-form.checkbox>
                    @endif

                    <div class="flex flex-row justify-end gap-2">
                        <x-button.primary wire:click="checkout" class="h-fit" wire:loading.attr="disabled">
                            <x-loading target="checkout" />
                            <div wire:loading.remove wire:target="checkout">
                                {{ __('product.checkout') }}
                            </div>
                        </x-button.primary>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
