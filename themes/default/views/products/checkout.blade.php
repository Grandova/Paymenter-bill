<div class="container checkout-page">
    <div class="client-panel">
    <div class="text-center mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold">{{ __('Configure your service') }}</h1>
        <p class="text-muted mt-2 text-sm">{{ __('Choose your plan and options, then review your order.') }}</p>
        <ol class="order-steps" aria-label="{{ __('Order progress') }}">
            <li aria-current="step"><span><x-ri-server-line class="size-6" /></span>{{ __('Configure') }}</li>
            <li><span><x-ri-file-list-3-line class="size-6" /></span>{{ __('Review order') }}</li>
            <li><span><x-ri-bank-card-line class="size-6" /></span>{{ __('Payment') }}</li>
        </ol>
    </div>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-6 min-w-0">
            @if ($products->count() > 1)
            <section class="checkout-section">
                <h2><x-ri-map-pin-line class="size-6" />{{ __('Select a location') }}</h2>
                <p class="text-muted text-sm mt-2 mb-6">{{ __('Choose a product to view its available plans and options.') }}</p>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($products as $location)
                    @php $available = $location->stock !== 0 && $location->price(currency: $currency)->available; @endphp
                    <a class="location-option {{ !$available ? 'is-unavailable' : '' }}"
                        @if ($available) href="{{ route('products.checkout', ['category' => $category, 'product' => $location->slug, 'edit' => $cartProductKey]) }}" wire:navigate
                        @else aria-disabled="true" @endif
                        @if ($location->id === $product->id) aria-current="true" @endif>
                        @if ($location->image)
                        <img src="{{ Storage::url($location->image) }}" alt="" class="size-12 rounded-lg object-cover" />
                        @else
                        <span class="location-icon"><x-ri-server-line class="size-6" /></span>
                        @endif
                        <span class="flex flex-col gap-1">
                            <span class="font-semibold">{{ $location->name }}</span>
                            <span class="text-muted text-sm">{{ $available ? $location->price(currency: $currency)->formatted->price : __('Unavailable') }}</span>
                        </span>
                        @if ($location->id === $product->id)
                        <x-ri-checkbox-circle-fill class="size-5 ml-auto shrink-0" />
                        @endif
                    </a>
                    @endforeach
                </div>
            </section>
            @endif
            <h2 class="text-xl font-semibold">{{ $product->name }}</h2>
            <div class="flex flex-row w-full gap-4">
                @if ($product->image)
                    <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="max-w-40">
                @endif
                <div class="max-h-28 overflow-y-auto w-full">
                    <article class="prose dark:prose-invert prose-sm">
                        {!! $product->description !!}
                    </article>
                </div>
            </div>
            @foreach ($product->configOptions as $configOption)
                @php
                    $showPriceTag = $configOption->children->filter(fn ($value) => !$value->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit, currency: $currency)->is_free)->count() > 0;
                @endphp
                <section class="checkout-section">
                <h2 class="mb-6"><x-ri-settings-3-line class="size-6" />{{ $configOption->name }}</h2>
                <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$plan" :currency="$currency">
                    @if ($configOption->type == 'select')
                        @foreach ($configOption->children as $configOptionValue)
                            @php
                                $optionPrice = $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit, currency: $currency);
                                $optionPriceLabel = $plan->type === 'recurring'
                                    ? __('services.price_every_period', [
                                        'price' => $optionPrice->formatted->price,
                                        'period' => $plan->billing_period > 1 ? $plan->billing_period : '',
                                        'unit' => trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period),
                                    ])
                                    : $optionPrice->formatted->price;
                            @endphp
                            <option value="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}
                                @if ($showPriceTag && $optionPrice->available)
                                    - {{ $optionPriceLabel }}
                                    @if ($optionPrice->has_setup_fee)
                                        ({{ __('product.first_payment_setup_fee', ['amount' => $optionPrice->formatted->setup_fee]) }})
                                    @endif
                                @endif
                            </option>
                        @endforeach
                    @elseif($configOption->type == 'radio')
                        @foreach ($configOption->children as $configOptionValue)
                            @php
                                $optionPrice = $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit, currency: $currency);
                                $optionPriceLabel = $plan->type === 'recurring'
                                    ? __('services.price_every_period', [
                                        'price' => $optionPrice->formatted->price,
                                        'period' => $plan->billing_period > 1 ? $plan->billing_period : '',
                                        'unit' => trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period),
                                    ])
                                    : $optionPrice->formatted->price;
                            @endphp
                            <div class="checkout-option flex items-center gap-3">
                                <input type="radio" id="{{ $configOptionValue->id }}" name="{{ $configOption->id }}"
                                    wire:model.live="configOptions.{{ $configOption->id }}"
                                    value="{{ $configOptionValue->id }}" />
                                <label for="{{ $configOptionValue->id }}">
                                    {{ $configOptionValue->name }}
                                    @if ($showPriceTag && $optionPrice->available)
                                        - {{ $optionPriceLabel }}
                                        @if ($optionPrice->has_setup_fee)
                                            ({{ __('product.first_payment_setup_fee', ['amount' => $optionPrice->formatted->setup_fee]) }})
                                        @endif
                                    @endif
                                </label>
                            </div>
                        @endforeach
                    @endif
                </x-form.configoption>
                </section>
            @endforeach
            <section class="checkout-section">
                <h2><x-ri-stack-line class="size-6" />{{ __('Select a plan') }}</h2>
                <p class="text-muted text-sm mt-2 mb-6">{{ __('Choose the billing period that suits you.') }}</p>
                <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach ($product->availablePlans($currency) as $availablePlan)
                    <label class="plan-option">
                        <input type="radio" wire:model.live="plan_id" value="{{ $availablePlan->id }}" name="plan_id" class="sr-only" />
                        <span class="font-semibold">{{ $availablePlan->name }}</span>
                        <strong class="text-2xl mt-4">{{ $availablePlan->price($currency)->formatted->price }}</strong>
                        @if ($availablePlan->type === 'recurring')
                        <span class="text-muted text-sm">{{ __('services.every_period', [
                            'period' => $availablePlan->billing_period > 1 ? $availablePlan->billing_period : '',
                            'unit' => trans_choice(__('services.billing_cycles.' . $availablePlan->billing_unit), $availablePlan->billing_period)
                        ]) }}</span>
                        @elseif ($availablePlan->type === 'one-time')
                        <span class="text-muted text-sm">{{ __('One Time') }}</span>
                        @endif
                        @if ($availablePlan->price($currency)->has_setup_fee)
                        <span class="text-muted text-sm mt-2">+ {{ $availablePlan->price($currency)->formatted->setup_fee }} {{ __('product.setup_fee') }}</span>
                        @endif
                        <x-ri-check-line class="plan-check size-5" />
                    </label>
                    @endforeach
                </div>
            </section>

            @foreach ($this->getCheckoutConfig() as $configOption)
                @php $configOption = (object) $configOption; @endphp
                <section class="checkout-section">
                <h2 class="mb-6"><x-ri-settings-3-line class="size-6" />{{ __($configOption->label ?? $configOption->name) }}</h2>
                <x-form.configoption :config="$configOption" :name="'checkoutConfig.' . $configOption->name">
                    @if ($configOption->type == 'select')
                        @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                            <option value="{{ $configOptionValue }}">
                                {{ $configOptionValueName }}
                            </option>
                        @endforeach
                    @elseif($configOption->type == 'radio')
                        @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                            <div class="checkout-option flex items-center gap-3">
                                <input type="radio" id="{{ $configOptionValue }}" name="{{ $configOption->name }}"
                                    wire:model.live="checkoutConfig.{{ $configOption->name }}"
                                    value="{{ $configOptionValue }}" />
                                <label for="{{ $configOptionValue }}">
                                    {{ $configOptionValueName }}
                                </label>
                            </div>
                        @endforeach
                    @endif
                </x-form.configoption>
                </section>
            @endforeach
        </div>
        <div class="checkout-summary flex flex-col sm:flex-row sm:items-center sm:justify-end gap-6 w-full">
            <h2 class="text-sm text-muted sm:mr-auto">
                {{ __('product.order_summary') }}
            </h2>
            @if ($total->total_tax > 0)
                <div class="font-semibold flex justify-between">
                    <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
                </div>
                <div class="font-semibold flex justify-between">
                    <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->formatted->total_tax }}
                </div>
            @endif
            @if ($total->setup_fee > 0)
                <div class="text-sm text-muted">
                    {{ __('product.first_payment_setup_fee', ['amount' => $total->formatted->setup_fee]) }}
                </div>
            @endif
            <div class="text-lg font-semibold flex justify-between gap-6">
                <h4>{{ __('product.total_today') }}:</h4> {{ $total }}
            </div>
            @if ($plan->type == 'recurring')
                <div class="text-sm font-semibold flex justify-between gap-4">
                    <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                    </h4> {{ $total->format($total->price) }}
                </div>
            @endif
            @if (($product->stock > 0 || !$product->stock) && $product->price(currency: $currency)->available)
                <div>
                    <x-button.primary wire:click="checkout" wire:loading.attr="disabled">
                        <x-loading target="checkout" />
                        <div wire:loading.remove wire:target="checkout">
                            {{ __('Review order') }}
                            <x-ri-arrow-right-line class="size-4 inline ml-2" />
                        </div>
                    </x-button.primary>
                </div>
            @endif
        </div>
    </div>
</div>
</div>
