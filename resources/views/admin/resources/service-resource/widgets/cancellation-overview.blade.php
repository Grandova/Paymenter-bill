<div class="fi-widget-wrapper rounded-lg shadow col-span-full p-4 flex justify-between items-center">
    <div class="flex  gap-2 items-center">
        <x-ri-error-warning-line class="size-5 text-yellow-500" />
        @if($record->status === 'cancelled')
        <p class="text-sm text-gray-950 dark:text-white">{{ __('This service is cancelled. Reason:') }} <span class="font-medium">{{$record->cancellation->reason }}</span></p>
        @else
        <p class="text-sm text-gray-950 dark:text-white">{{ __('This service is pending cancellation') }}
            {{ $record->expires_at ? __('Cancellation scheduled for :date.', ['date' => $record->expires_at->translatedFormat(__('general.date_format'))]) : '' }}
            {{ __('Reason') }}: <span class="font-medium">{{
                $record->cancellation->reason }}</span>
        </p>
        @endif
    </div>
    <a href="{{ \App\Admin\Resources\ServiceCancellationResource::getUrl('edit', ['record' => $record->cancellation->id]) }}"
        class="fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-300 fi-link fi-size-sm">{{ __('View Cancellation') }}</a>
</div>