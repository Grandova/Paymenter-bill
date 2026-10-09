<div class="container client-catalog">
    <div class="client-panel">
        <div class="mb-7">
            <p class="text-sm text-muted mb-2">{{ __('Order new service') }}</p>
            <h1 class="text-3xl font-bold">{{ $category->name }}</h1>
            <article class="prose prose-sm dark:prose-invert text-muted mt-3 max-w-none">{!! $category->description !!}</article>
        </div>
        <nav class="catalog-tabs" aria-label="{{ __('Categories') }}">
            @foreach ($categories as $ccategory)
            <a href="{{ route('category.show', ['category' => $ccategory->slug]) }}" wire:navigate
                @if ($category->id == $ccategory->id || $category->parent_id == $ccategory->id) aria-current="page" @endif>
                {{ $ccategory->name }}
            </a>
            @endforeach
        </nav>
        @if ($childCategories->isNotEmpty())
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
            @foreach ($childCategories as $childCategory)
            <a href="{{ route('category.show', ['category' => $childCategory->slug]) }}" wire:navigate class="catalog-product">
                @if ($childCategory->image)
                <img src="{{ Storage::url($childCategory->image) }}" alt="" class="size-14 rounded-lg object-cover mb-4" />
                @endif
                <h2 class="text-lg font-semibold">{{ $childCategory->name }}</h2>
                @if (theme('show_category_description', true))
                <article class="prose prose-sm dark:prose-invert text-muted mt-3">{!! $childCategory->description !!}</article>
                @endif
                <span class="inline-flex items-center gap-2 text-sm mt-5">{{ __('common.button.view_all') }}<x-ri-arrow-right-line class="size-4" /></span>
            </a>
            @endforeach
        </div>
        @endif
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($products as $product)
            <div class="catalog-product flex flex-col">
                <div class="flex items-center justify-between gap-3 mb-5">
                    @if ($product->image)
                    <img src="{{ Storage::url($product->image) }}" alt="" class="size-14 rounded-lg object-cover" />
                    @else
                    <span class="location-icon"><x-ri-server-line class="size-6" /></span>
                    @endif
                    @if ($product->stock === 0)
                    <span class="service-status">{{ __('Unavailable') }}</span>
                    @endif
                </div>
                <h2 class="text-xl font-semibold">{{ $product->name }}</h2>
                @if (theme('direct_checkout', false) && $product->description)
                <article class="prose prose-sm dark:prose-invert text-muted mt-3">{!! $product->description !!}</article>
                @endif
                <div class="mt-5 mb-6">
                    <strong class="text-2xl">{{ $product->price()->formatted->price }}</strong>
                </div>
                <div class="mt-auto flex items-center gap-3">
                    @if ($product->stock !== 0 && $product->price()->available)
                    <a href="{{ route('products.checkout', ['category' => $product->category, 'product' => $product->slug]) }}" wire:navigate class="flex-1">
                        <x-button.primary>{{ __('Configure') }}<x-ri-arrow-right-line class="size-4" /></x-button.primary>
                    </a>
                    @endif
                    <a href="{{ route('products.show', ['category' => $product->category, 'product' => $product->slug]) }}" wire:navigate class="text-sm text-muted hover:underline">{{ __('common.button.view') }}</a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
