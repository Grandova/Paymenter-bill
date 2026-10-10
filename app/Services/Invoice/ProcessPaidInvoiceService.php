<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\DB;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): void
    {
        // Update services if invoice is paid (suspended -> active etc.)
        $invoice->items->each(function ($item) use ($invoice) {
            if ($item->reference_type == Service::class) {
                $service = $item->reference;
                if (!$service || !($service instanceof Service)) {
                    return;
                }
                $cycleChange = ServiceUpgrade::where('invoice_id', $invoice->id)
                    ->where('service_id', $service->id)
                    ->where('type', 'renewal_cycle')
                    ->where('status', ServiceUpgrade::STATUS_PENDING)
                    ->first();
                if ($cycleChange) {
                    (new ServiceUpgradeService)->handle($cycleChange);
                } else {
                    (new RenewServiceService)->handle($service);
                }
            } elseif ($item->reference_type == ServiceUpgrade::class) {
                $serviceUpgrade = $item->reference;
                if (!$serviceUpgrade || $serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING || !($serviceUpgrade instanceof ServiceUpgrade)) {
                    return;
                }

                // Handle the upgrade
                (new ServiceUpgradeService)->handle($serviceUpgrade);
            } elseif ($item->reference_type == Credit::class) {
                DB::transaction(function () use ($invoice, $item) {
                    $user = $invoice->user()->lockForUpdate()->first();
                    $credit = $user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();

                    if ($credit) {
                        $credit->amount = number_format(((int) round((float) $credit->amount * 100) + (int) round((float) $item->price * 100)) / 100, 2, '.', '');
                        $credit->recordAs('deposit', __('account.credit_deposit', ['currency' => $invoice->currency_code]), $invoice)->save();
                    } else {
                        $user->credits()->make([
                            'currency_code' => $invoice->currency_code,
                            'amount' => $item->price,
                        ])->recordAs('deposit', __('account.credit_deposit', ['currency' => $invoice->currency_code]), $invoice)->save();
                    }
                });
            }
        });
    }
}
