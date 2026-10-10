<?php

namespace App\Jobs\Server;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\Service;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TerminateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->service->refresh();
        if ($this->service->status === Service::STATUS_CANCELLED) {
            return;
        }

        $immediateCancellation = in_array($this->service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            && $this->service->cancellation()->where('type', 'immediate')->exists();
        $pendingInvoiceHasPayment = $this->service->invoices()
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
            ->exists();
        if ($pendingInvoiceHasPayment) {
            return;
        }

        $terminationDays = (int) config('settings.cronjob_order_terminate', 3);
        if (!$immediateCancellation && ($this->service->status !== Service::STATUS_SUSPENDED
            || !$this->service->expires_at
            || $this->service->expires_at->gte(now()->subDays($terminationDays)))) {
            return;
        }

        $data = [];

        try {
            $data = ExtensionHelper::terminateServer($this->service);
            if ($data === false) {
                throw new Exception(__('Failed to terminate server'));
            }
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        $terminated = DB::transaction(function () {
            $service = Service::query()->lockForUpdate()->findOrFail($this->service->id);
            if ($service->status === Service::STATUS_CANCELLED) {
                return false;
            }

            $service->update(['status' => Service::STATUS_CANCELLED]);
            $service->removePendingRenewalInvoiceItems(__('Service terminated'));

            if ($service->product->stock !== null) {
                $service->product->increment('stock', $service->quantity);
            }

            return true;
        });

        if (!$terminated) {
            return;
        }

        if ($this->sendNotification) {
            NotificationHelper::serverTerminatedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
