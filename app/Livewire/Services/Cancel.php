<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Validate;

class Cancel extends Component
{
    public Service $service;

    #[Validate('required|in:end_of_period,immediate')]
    public $type = 'end_of_period';

    #[Validate('required|max:255')]
    public $reason = '';

    public function cancelService()
    {
        $this->authorize('view', $this->service);

        $this->validate();

        $created = DB::transaction(function () {
            $service = Service::whereKey($this->service->id)->lockForUpdate()->firstOrFail();
            $this->authorize('view', $service);

            if (!$service->cancellable) {
                return false;
            }

            $pendingInvoices = $service->invoices()
                ->where('status', Invoice::STATUS_PENDING)
                ->orderBy('invoices.id')
                ->lockForUpdate()
                ->get();
            if ($pendingInvoices->contains(fn ($invoice) => !$invoice->canBeEdited())) {
                return 'payment_processing';
            }

            // Event hook will handle the cancellation (if its immediate or end of period)
            ServiceCancellation::create([
                'service_id' => $service->id,
                'type' => $this->type,
                'reason' => $this->reason,
            ]);
            $service->removePendingRenewalInvoiceItems(__('services.cancellation_requested'));

            return true;
        });

        if ($created === 'payment_processing') {
            return $this->notify(__('services.cancellation_payment_processing'), 'error');
        }

        if (!$created) {
            return $this->notify(__('This service cannot be cancelled'), 'error');
        }

        $this->notify(__('services.cancellation_requested'), 'success', true);

        $this->redirect(route('services.show', $this->service), true);
    }

    public function render()
    {
        return view('services.cancel')->layoutData([
            'title' => __('services.cancellation', ['service' => $this->service->product->name]),
        ]);
    }
}
