<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Jobs\Server\UpgradeJob;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    /**
     * Handle the uploaded extension file.
     * The added file is always a zip file.
     *
     * @return void
     */
    public function handle(ServiceUpgrade $serviceUpgrade)
    {
        return DB::transaction(function () use ($serviceUpgrade) {
            if ($serviceUpgrade->type === 'renewal_cycle') {
                $service = Service::query()->whereKey($serviceUpgrade->service_id)->lockForUpdate()->firstOrFail();
                $serviceUpgrade = ServiceUpgrade::query()->whereKey($serviceUpgrade->id)->lockForUpdate()->firstOrFail();
                if ($serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING) {
                    return;
                }

                if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                    return;
                }

                $service->plan_id = $serviceUpgrade->plan_id;
                $service->save();
                (new RenewServiceService)->handle($service);

                $service->refresh();
                $service->price = $service->calculatePrice();
                $service->save();

                $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
                $serviceUpgrade->save();

                return;
            }

            $service = Service::query()->whereKey($serviceUpgrade->service_id)->lockForUpdate()->firstOrFail();
            $serviceUpgrade = ServiceUpgrade::query()->whereKey($serviceUpgrade->id)->lockForUpdate()->firstOrFail();
            if ($serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING) {
                return;
            }

            $products = Product::query()
                ->whereIn('id', [$service->product_id, $serviceUpgrade->product_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $oldProduct = $products->get($service->product_id);
            $newProduct = $products->get($serviceUpgrade->product_id);

            if ($service->product_id !== $serviceUpgrade->product_id && $newProduct->stock !== null && !$serviceUpgrade->stock_reserved) {
                if ($newProduct->stock < $service->quantity) {
                    throw new DisplayException(__('product.out_of_stock', ['product' => $newProduct->name]));
                }
                $newProduct->decrement('stock', $service->quantity);
            }

            if ($service->product_id !== $serviceUpgrade->product_id && $oldProduct->stock !== null) {
                $oldProduct->increment('stock', $service->quantity);
            }

            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->stock_reserved = false;
            $serviceUpgrade->save();
            $service->plan_id = $serviceUpgrade->plan_id;
            $service->product_id = $serviceUpgrade->product_id;
            $service->save();

            $service->refresh();

            // Update service configurations - remove old configs and add new ones
            $newConfigOptionIds = $serviceUpgrade->configs->pluck('config_option_id')->toArray();

            // Delete configs that are no longer applicable
            $service->configs()
                ->whereNotIn('config_option_id', $newConfigOptionIds)
                ->delete();

            // Update or create new configs
            foreach ($serviceUpgrade->configs as $config) {
                $service->configs()->updateOrCreate(
                    ['config_option_id' => $config->config_option_id],
                    ['config_value_id' => $config->config_value_id]
                );
            }

            $service->refresh();

            $service->price = $service->calculatePrice();
            $service->save();

            // Is there a pending renewal invoice? Update it.
            $pendingInvoice = $service->invoices()
                ->where('status', 'pending')
                ->first();

            if ($pendingInvoice) {
                $item = $pendingInvoice->items()
                    ->where('reference_type', Service::class)
                    ->where('reference_id', $service->id)
                    ->first();
                if ($item) {
                    $item->price = $service->price;
                    $item->save();
                }
            }

            if ($service->product->server) {
                UpgradeJob::dispatch($service)->afterCommit();
            }
        });
    }
}
