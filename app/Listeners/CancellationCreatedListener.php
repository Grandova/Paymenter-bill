<?php

namespace App\Listeners;

use App\Events\ServiceCancellation\Created;
use App\Jobs\Server\TerminateJob;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class CancellationCreatedListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(Created $event): void
    {
        if ($event->cancellation->type == 'immediate') {
            $service = $event->cancellation->service;
            $usesClicd = $service->product->server?->extension === 'Clicd';
            $hasClicdInstance = $usesClicd && $service->properties()->where('key', 'clicd_instance_uuid')->exists();

            if (in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED]) && $service->product->server && (!$usesClicd || $hasClicdInstance)) {
                TerminateJob::dispatch($service)->afterCommit();

                return;
            }

            if ($hasClicdInstance) {
                return;
            }

            DB::transaction(function () use ($service) {
                $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
                if ($service->status === Service::STATUS_CANCELLED) {
                    return;
                }

                $service->update(['status' => Service::STATUS_CANCELLED]);
                $service->removePendingRenewalInvoiceItems(__('services.cancellation_requested'));

                if ($service->product->stock !== null) {
                    $service->product->increment('stock', $service->quantity);
                }
            });
        }
        // If the cancellation is scheduled, we don't need to do anything as it will be handled by the cron job
    }
}
