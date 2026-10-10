<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Service;

class RenewServiceService
{
    /**
     * Handle the service renewal.
     *
     * @return void
     */
    public function handle(Service $service)
    {
        $service->refresh();
        if ($service->status === Service::STATUS_CANCELLED) {
            return;
        }

        $wasPending = $service->status == Service::STATUS_PENDING;
        $wasSuspended = $service->status == Service::STATUS_SUSPENDED;

        if (!$wasPending) {
            $service->renewal_count++;
        }
        $service->expires_at = $service->calculateNextDueDate();
        $service->status = Service::STATUS_ACTIVE;
        $service->save();

        if ($service->product->server) {
            if ($wasSuspended) {
                UnsuspendJob::dispatch($service)->afterCommit();
            } elseif ($wasPending) {
                CreateJob::dispatch($service)->afterCommit();
            }
        }
    }
}
