<x-filament-panels::page>
    @assets
    <script src="{{ asset('js/ansi_up/ansi_up.min.js') }}"></script>
    @endassets

    <div class="flex flex-col gap-6">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('System Updates') }}
            </x-slot>

            @if(config('app.version') == 'beta')
            <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300"
                role="alert">
                <strong>{{ __('Beta release:') }}</strong> {{ __('This version may contain unfinished features or unexpected issues.') }}
            </div>
            @endif

            @if(config('app.version') == 'beta' && config('settings.latest_commit') != config('app.commit'))
            <div class="flex flex-col gap-2">
                <div>
                    <strong>{{ __('Latest commit:') }}</strong> {{ config('settings.latest_commit') }}
                </div>
                <div>
                    <strong>{{ __('Your commit:') }}</strong> {{ config('app.commit') }}
                </div>
                <p>{{ __('Review the') }} <a class="text-primary-600 underline" href="https://paymenter.org/docs/installation/updating"
                    target="_blank">{{ __('update documentation') }}</a> {{ __('before continuing.') }}</p>

                <p class="mt-2">{{ __('Alternatively, use the web updater. This beta feature is provided at your own risk.') }}</p>
                <div class="mt-2">
                    {{ $this->update }}
                </div>
            </div>
            @elseif(config('app.version') != config('settings.latest_version') && config('app.version') != 'beta')
            <div class="flex flex-col gap-2">
                <div>
                    <strong>{{ __('Latest version:') }}</strong> {{ config('settings.latest_version') }}
                </div>
                <div>
                    <strong>{{ __('Your version:') }}</strong> {{ config('app.version') }}
                </div>
                <p>{{ __('Review the') }} <a class="text-primary-600 underline" href="https://paymenter.org/docs/installation/updating"
                    target="_blank">{{ __('update documentation') }}</a> {{ __('before continuing.') }}</p>

                <p class="mt-2">{{ __('Alternatively, use the web updater. Use at your own risk.') }}</p>
                <div class="mt-2">
                    {{ $this->update }}
                </div>
            </div>
            @else
            <div class="flex flex-col gap-2">
                <div>
                    <strong>{{ __('Latest version:') }}</strong> {{ config('settings.latest_version') ?? config('app.version') }}
                </div>
                <div>
                    <strong>{{ __('Your version:') }}</strong> {{ config('app.version') }}
                </div>
                <p class="text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ __('You are up to date!') }}</p>
            </div>
            @endif

            <code>
                <pre id="update-result" class="mt-2" x-data="{ output: '' }" x-html="output" x-on:update-completed.window="output = (new AnsiUp()).ansi_to_html($event.detail[0].output);"></pre>
            </code>
        </x-filament::section>

        <div>
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>