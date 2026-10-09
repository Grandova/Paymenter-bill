<div class="client-sidebar-links">
    @foreach (array_merge(\App\Classes\Navigation::getDashboardLinks(), \App\Classes\Navigation::getLinks()) as $nav)
        @if (!empty($nav['children']))
        <div x-data="{ expanded: {{ $nav['active'] ? 'true' : 'false' }} }">
            <button @click="expanded = !expanded" :aria-expanded="expanded" class="client-nav-link w-full">
                @isset($nav['icon'])<x-dynamic-component :component="$nav['icon']" class="size-5 text-muted" />@endisset
                <span class="flex-1 text-left">{{ $nav['name'] }}</span>
                <x-ri-arrow-right-s-line class="size-4 transition-transform" x-bind:class="{ 'rotate-90': expanded }" />
            </button>
            <div x-show="expanded" x-collapse x-cloak class="pl-7">
                @foreach ($nav['children'] as $child)
                    @if ($child['condition'] ?? true)
                    <a href="{{ $child['url'] }}" @click="menuOpen = false" @if($child['spa'] ?? true) wire:navigate @endif @class(['client-nav-link', 'is-active' => $child['active']])>{{ $child['name'] }}</a>
                    @endif
                @endforeach
            </div>
        </div>
        @else
        <a href="{{ $nav['url'] }}" @click="menuOpen = false" @if($nav['spa'] ?? true) wire:navigate @endif @class(['client-nav-link', 'is-active' => $nav['active']])>
            @isset($nav['icon'])<x-dynamic-component :component="$nav['icon']" class="size-5 text-muted" />@endisset
            {{ $nav['name'] }}
        </a>
        @endif
    @endforeach
</div>
