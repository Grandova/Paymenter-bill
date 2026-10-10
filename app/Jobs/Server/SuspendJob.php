<?php

namespace App\Jobs\Server;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\Service;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $shouldSuspend = DB::transaction(function () {
            $service = Service::query()->lockForUpdate()->findOrFail($this->service->id);
            if ($service->status !== Service::STATUS_SUSPENDED
                || !$service->expires_at
                || $service->expires_at->gte(now()->subDays((int) config('settings.cronjob_order_suspend', 2)))
                || ($service->suspend_hold_until && $service->suspend_hold_until->gte(today()))) {
                return false;
            }

            $pendingInvoiceHasPayment = $service->invoices()
                ->where('status', Invoice::STATUS_PENDING)
                ->whereHas('transactions', function ($query) {
                    $query->where(function ($query) {
                        $query->where('status', InvoiceTransactionStatus::Succeeded->value)
                            ->whereRaw('amount > refunded_amount + credited_amount');
                    })
                        ->orWhere(function ($query) {
                            $query->where('status', InvoiceTransactionStatus::Processing->value)
                                ->where('created_at', '>=', now()->subDay());
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($pendingInvoiceHasPayment) {
                $service->update(['status' => Service::STATUS_ACTIVE]);

                return false;
            }

            $this->service = $service;

            return true;
        });

        if (!$shouldSuspend) {
            return;
        }

        $data = [];

        try {
            $data = ExtensionHelper::suspendServer($this->service);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        if ($this->sendNotification) {
            NotificationHelper::serverSuspendedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
