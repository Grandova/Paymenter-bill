<div class="container">
    @php $startingPlan = $product->availablePlans($currency)->sortBy(fn ($plan) => $plan->price($currency)->price)->first(); @endphp
    <div class="client-panel flex flex-col gap-8 @if ($product->image) lg:grid grid-cols-2 @endif">
        @if ($product->image)
        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
            class="w-full h-96 object-contain object-center rounded-md">
        @endif
        <div class="flex flex-col">
            @if ($product->stock === 0)
            <span class="text-xs font-medium me-2 px-2.5 py-0.5 rounded bg-red-900 text-red-300 w-fit mb-3">
                {{ __('product.out_of_stock', ['product' => $product->name]) }}
            </span>
            @elseif($product->stock > 0)
            <span class="text-xs font-medium me-2 px-2.5 py-0.5 rounded bg-green-900 text-green-300 w-fit mb-3">
                {{ __('product.in_stock') }}
            </span>
            @endif
            <div class="flex flex-row justify-between">
                <div>
                    <h2 class="text-3xl font-bold">{{ $product->name }}</h2>
                    <h3 class="text-xl font-semibold">
                        {{ $product->price(currency: $currency)->formatted->price }}
                    </h3>
                    @if ($startingPlan?->type === 'recurring')
                    <span class="text-sm text-muted">{{ __('services.every_period', [
                        'period' => $startingPlan->billing_period > 1 ? $startingPlan->billing_period : '',
                        'unit' => trans_choice(__('services.billing_cycles.' . $startingPlan->billing_unit), $startingPlan->billing_period)
                    ]) }}</span>
                    @if (($startingPlan->price($currency)->setup_fee ?? 0) > 0)
                    <span class="block text-sm text-muted">{{ __('product.first_payment_setup_fee', ['amount' => $startingPlan->price($currency)->formatted->setup_fee]) }}</span>
                    @endif
                    @elseif ($startingPlan?->type === 'one-time')
                    <span class="text-sm text-muted">{{ __('One Time') }}</span>
                    @endif
                </div>
            </div>
            <article class="my-4 prose dark:prose-invert">
                {!! $product->description !!}
            </article>

            <section class="mt-6">
                <h3 class="text-xl font-semibold mb-4">{{ __('product.available_billing_plans') }}</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($product->availablePlans($currency) as $availablePlan)
                    @php $planPrice = $availablePlan->price($currency); @endphp
                    <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug, 'plan' => $availablePlan->id]) }}" wire:navigate class="catalog-product flex flex-col gap-2">
                        <span class="font-semibold">{{ $availablePlan->name }}</span>
                        <strong class="text-2xl">{{ $planPrice->formatted->price }}</strong>
                        @if ($availablePlan->type === 'recurring')
                        <span class="text-sm text-muted">{{ __('services.every_period', [
                            'period' => $availablePlan->billing_period > 1 ? $availablePlan->billing_period : '',
                            'unit' => trans_choice(__('services.billing_cycles.' . $availablePlan->billing_unit), $availablePlan->billing_period)
                        ]) }}</span>
                        @elseif ($availablePlan->type === 'one-time')
                        <span class="text-sm text-muted">{{ __('One Time') }}</span>
                        @endif
                        @if ($planPrice->has_setup_fee)
                        <span class="text-sm text-muted">{{ __('product.first_payment_setup_fee', ['amount' => $planPrice->formatted->setup_fee]) }}</span>
                        @endif
                        <span class="text-sm mt-auto">{{ __('product.select_plan') }} <x-ri-arrow-right-line class="size-4 inline" /></span>
                    </a>
                    @endforeach
                </div>
            </section>

            @if ($product->stock !== 0 && $product->price(currency: $currency)->available)
            <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug]) }}"
                wire:navigate>
                <x-button.primary>{{ __('Configure') }}</x-button.primary>
            </a>
            @endif
        </div>
    </div>
</div>
