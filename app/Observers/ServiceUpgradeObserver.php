<?php

namespace App\Observers;

use App\Events\ServiceUpgrade as ServiceUpgradeEvent;
use App\Models\Product;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeObserver
{
    /**
     * Handle the ServiceUpgrade "created" event.
     */
    public function created(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Created($serviceUpgrade));
    }

    /**
     * Handle the ServiceUpgrade "updated" event.
     */
    public function updated(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Updated($serviceUpgrade));

        if ($serviceUpgrade->wasChanged('status') && $serviceUpgrade->status === ServiceUpgrade::STATUS_CANCELLED) {
            DB::transaction(function () use ($serviceUpgrade) {
                $upgrade = ServiceUpgrade::query()->whereKey($serviceUpgrade->id)->lockForUpdate()->first();
                if (!$upgrade?->stock_reserved) {
                    return;
                }

                $product = Product::query()->whereKey($upgrade->product_id)->lockForUpdate()->first();
                if ($product?->stock !== null) {
                    $product->increment('stock', $upgrade->service->quantity);
                }

                $upgrade->stock_reserved = false;
                $upgrade->saveQuietly();
            });
        }
    }

    /**
     * Handle the ServiceUpgrade "deleted" event.
     */
    public function deleted(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Deleted($serviceUpgrade));
    }
}
