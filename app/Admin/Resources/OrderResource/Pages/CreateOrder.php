<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\RenewServiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        $services = $this->record->services;
        $services->each(fn (Service $service) => $service->loadMissing(['plan', 'configs.configValue']));

        foreach ($services->groupBy('product_id') as $productId => $productServices) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $quantity = $productServices->sum('quantity');

            if ($product->stock !== null) {
                if ($product->stock < $quantity) {
                    Notification::make()
                        ->title(__('Whoops!'))
                        ->body(__('product.out_of_stock', ['product' => $product->name]))
                        ->danger()
                        ->send();

                    throw (new Halt)->rollBackDatabaseTransaction();
                }

                $product->decrement('stock', $quantity);
            }
        }

        $billableServices = $services->filter(fn (Service $service) => ($service->price + $this->initialSetupFee($service)) * $service->quantity > 0);

        foreach ($services->diff($billableServices) as $service) {
            (new RenewServiceService)->handle($service);
        }

        if ($billableServices->isEmpty()) {
            return;
        }

        $invoice = new Invoice([
            'user_id' => $this->record->user_id,
            'currency_code' => $this->record->currency_code,
            'due_at' => now()->addDays(7),
            'status' => config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_PENDING,
        ]);
        $invoice->save();

        foreach ($billableServices as $service) {
            $invoice->items()->create([
                'description' => $service->description,
                'price' => $service->price + $this->initialSetupFee($service),
                'quantity' => $service->quantity,
                'reference_id' => $service->id,
                'reference_type' => get_class($service),
            ]);
        }
    }

    private function initialSetupFee(Service $service): float
    {
        $setupFee = (float) ($service->plan->price($service->currency_code)->setup_fee ?? 0);
        foreach ($service->configs as $config) {
            $setupFee += (float) ($config->configValue?->price(
                billing_period: $service->plan->billing_period,
                billing_unit: $service->plan->billing_unit,
                currency: $service->currency_code
            )->setup_fee ?? 0);
        }

        return $setupFee;
    }
}
