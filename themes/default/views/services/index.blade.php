<div @class(['container client-services' => !$dashboard, 'client-services' => $dashboard])>
    <div class="client-panel">
        <div class="mb-6">
            <h1 class="text-2xl font-bold">{{ __('Your services') }}</h1>
            <p class="mt-3 text-sm text-muted">{{ __('Manage your services and billing information here.') }}</p>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 mb-6">
            <x-form.input name="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search services by name')" :aria-label="__('Search services by name')" />
            <x-form.select name="status" wire:model.live="status" :aria-label="__('services.status')">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['active', 'pending', 'suspended', 'cancelled'] as $value)
                    <option value="{{ $value }}">{{ __('services.statuses.' . $value) }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div class="overflow-x-auto">
            <table class="service-table">
                <thead>
                    <tr>
                        <th>{{ __('services.name') }}</th>
                        <th>{{ __('services.billing_cycle') }}</th>
                        <th>{{ __('services.renews_on') }}</th>
                        <th>{{ __('services.status') }}</th>
                        <th>{{ __('services.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($services as $service)
                    <tr wire:key="service-{{ $service->id }}">
                        <td>
                            <a href="{{ route('services.show', $service) }}" wire:navigate class="font-semibold hover:underline">{{ $service->label }}</a>
                            <p class="text-muted mt-1">{{ $service->product->category->name }}</p>
                            @if($service->product->server?->extension === 'Clicd')
                            <p class="text-muted mt-1 font-mono text-xs">{{ $service->properties->firstWhere('key', 'clicd_ip')?->value ?: $service->properties->firstWhere('key', 'clicd_ipv6')?->value }}</p>
                            @endif
                        </td>
                        <td>
                            <span class="font-medium">{{ $service->formattedPrice }}</span>
                            @if($service->plan->type === 'recurring')
                            <p class="text-muted mt-1">{{ __('services.every_period', ['period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '', 'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit), $service->plan->billing_period)]) }}</p>
                            @endif
                        </td>
                        <td>{{ $service->expires_at?->translatedFormat(__('general.date_format')) ?? '—' }}</td>
                        <td>
                            <span class="service-status service-status-{{ $service->status }}">{{ __('services.statuses.' . $service->status) }}</span>
                            @if($service->product->server?->extension === 'Clicd')
                            <p class="text-muted mt-2 text-xs">{{ __('Last synced status') }}：{{ ['running' => '运行中', 'stopped' => '已关机', 'missing' => '节点未找到实例'][$service->properties->firstWhere('key', 'clicd_status')?->value] ?? '未同步' }}</p>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('services.show', $service) }}" wire:navigate class="inline-flex items-center gap-2 border border-neutral rounded-md px-3 py-2 hover:bg-background">
                                <x-ri-settings-3-line class="size-4" />{{ __('Manage') }}
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted py-12">{{ __('services.no_services') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $services->links() }}</div>
    </div>
</div>
