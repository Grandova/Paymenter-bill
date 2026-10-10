<?php

namespace App\Livewire\Invoices;

use App\Classes\PDF;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    #[Locked]
    public Invoice $invoice;

    public $checkPayment = false;

    private $pay = null;

    #[Url('pay', except: false, nullable: true)]
    public $showPayModal = false;

    public $lastChecked = null;

    public $selectedMethod = null;

    public $setAsDefault = false;

    public function mount()
    {
        $this->authorize('view', $this->invoice);

        if (Request::has('checkPayment') && $this->invoice->status === 'pending') {
            $this->checkPayment = true;
        }
        if ($this->invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
            $this->checkPayment = true;
        }

        // Load relations
        $this->invoice->load('transactions', 'transactions.gateway', 'transactions.invoice', 'adjustmentNotes');

        if ($this->showPayModal && $this->invoice->status !== 'pending') {
            $this->showPayModal = false;
        }
    }

    #[Computed]
    public function gateways()
    {
        return ExtensionHelper::getCheckoutGateways($this->invoice->remaining, $this->invoice->currency_code, 'invoice', $this->invoice->items);
    }

    #[Computed]
    public function paymentMethods()
    {
        return ExtensionHelper::getBillingAgreementGateways($this->invoice->currency_code, $this->invoice->remaining, $this->invoice->items);
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        return Auth::user()->billingAgreements()->whereHas('gateway')->with('gateway')->get();
    }

    #[Computed]
    public function recurringServices()
    {
        return $this->invoice->items()
            ->where('reference_type', Service::class)
            ->whereNotNull('reference_id')
            ->whereHasMorph('reference', [Service::class], function ($query) {
                $query->whereHas('plan', function ($planQuery) {
                    $planQuery->whereNotIn('type', ['one-time', 'free']);
                });
            });
    }

    public function updatedShowPayModal($value)
    {
        if ($value && $this->invoice->status !== 'pending') {
            $this->showPayModal = false;
        }
    }

    public function processPayment()
    {
        abort_unless($this->invoice->user_id === Auth::id(), 404);

        if ($this->invoice->status !== 'pending') {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }
        if ($this->invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
            return $this->notify(__('A payment for this invoice is already being processed. Please wait before trying again.'), 'error');
        }

        if (is_null($this->selectedMethod)) {
            return;
        }

        if ($this->selectedMethod === 'credit') {
            if (!config('settings.credits_enabled')) {
                return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
            }
            if ($this->invoice->items->contains(fn ($item) => $item->reference_type === Credit::class)) {
                return $this->notify(__('This invoice cannot be paid with credits.'), 'error');
            }

            return $this->payWithCredit();
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);

            return $this->payWithMethod($gatewayId);
        }

        if ($this->setAsDefault) {
            $invoiceItems = $this->recurringServices()->get();
            $agreement = Auth::user()->billingAgreements()->where('ulid', $this->selectedMethod)->with('gateway')->first();
            if (!$agreement) {
                return $this->notify(__('Invalid payment method.'), 'error');
            }
            if (!$agreement->gateway || !in_array($agreement->gateway->id, array_column($this->paymentMethods, 'id'))) {
                return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
            }

            foreach ($invoiceItems as $invoiceItem) {
                $service = $invoiceItem->reference;
                $service->update(['billing_agreement_id' => $agreement->id]);
            }

            if ($invoiceItems->count() > 0) {
                $this->notify(__('Default payment method has been updated for recurring services.'), 'success');
            }
        }

        return $this->payWithSavedMethod($this->selectedMethod);
    }

    private function payWithMethod($methodId)
    {
        if (!in_array($methodId, array_column($this->gateways, 'id'))) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        $this->pay = ExtensionHelper::pay(Gateway::where('id', $methodId)->first(), $this->invoice);

        if (is_string($this->pay)) {
            $this->redirect($this->pay);
        }
    }

    private function payWithCredit()
    {
        DB::transaction(function () {
            $invoice = Invoice::whereKey($this->invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->user_id !== Auth::id()) {
                abort(404);
            }
            if ($invoice->status !== Invoice::STATUS_PENDING || $invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
                $this->notify(__('This invoice cannot be paid.'), 'error');

                return;
            }

            $remaining = $invoice->remaining;
            $user = Auth::user()->newQuery()->whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            $spent = Credit::spend($user, $invoice->currency_code, $remaining, type: 'invoice_payment', reference: $invoice);
            if ($spent > 0) {
                ExtensionHelper::addPayment($invoice, null, amount: $spent, isCreditTransaction: true);

                if ($spent >= $remaining) {
                    return $this->redirect(route('invoices.show', $this->invoice), true);
                }

                $this->invoice = $this->invoice->fresh();
                $this->notify(__('Part of the invoice has been paid with credits. Please pay the remaining amount'));
            }
        });
    }

    private function payWithSavedMethod($agreementUlid)
    {
        $agreement = Auth::user()->billingAgreements()->where('ulid', $agreementUlid)->with('gateway')->first();
        if (!$agreement || !$agreement->gateway) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        if (!in_array($agreement->gateway->id, array_column($this->paymentMethods, 'id'))) {
            return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
        }

        $success = ExtensionHelper::charge($agreement->gateway, $this->invoice, $agreement);

        if ($success === true) {
            $this->notify(__('Successfully charged the saved payment method.'), 'success');

            return $this->redirect(route('invoices.show', $this->invoice) . '?checkPayment', true);
        } else {
            return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
        }
    }

    public function exitPay()
    {
        $this->pay = null;
        // Dispatch event so extensions can do their thing
        $this->dispatch('invoice.payment.cancelled', $this->invoice);
        // Refresh invoice status
        $this->redirect(route('invoices.show', $this->invoice), true);
    }

    public function cancelInvoice(): void
    {
        DB::transaction(function () {
            $invoice = Invoice::whereKey($this->invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->user_id === Auth::id(), 403);

            if ($invoice->status !== Invoice::STATUS_PENDING || $invoice->transactions()->whereIn('status', [
                InvoiceTransactionStatus::Processing->value,
                InvoiceTransactionStatus::Succeeded->value,
            ])->exists()) {
                $this->notify(__('The invoice cannot be cancelled right now.'), 'error');

                return;
            }

            $invoice->update([
                'status' => Invoice::STATUS_CANCELLED,
                'cancellation_reason' => __('Unpaid invoice cancelled by customer'),
            ]);
            $invoice->cancelPendingServices();
            $this->checkPayment = false;
            $this->lastChecked = null;
            $this->showPayModal = false;
            $this->invoice = $invoice->fresh()->load('transactions', 'transactions.gateway', 'transactions.invoice', 'adjustmentNotes');
            $this->notify(__('Unpaid invoice has been cancelled.'), 'success');
        });
    }

    public function checkPaymentStatus()
    {
        $this->invoice->refresh();

        // Check for transactions that failed since lastChecked
        if ($this->lastChecked) {
            $failedSinceLastCheck = $this->invoice->transactions()
                ->where('status', InvoiceTransactionStatus::Failed)
                ->where('updated_at', '>', $this->lastChecked)
                ->exists();

            if ($failedSinceLastCheck) {
                $this->notify(__('Payment failed. Please try again or use a different payment method.'), 'error');
                $this->checkPayment = false;
                $this->lastChecked = null;

                return;
            }
        }

        // Update lastChecked to current time
        $this->lastChecked = now();

        // Check if invoice is paid
        if ($this->invoice->status === 'paid') {
            $this->notify(__('The invoice has been paid.'), 'success');
            $this->checkPayment = false;
            $this->lastChecked = null;
        }

        // Skip render if still checking
        if ($this->checkPayment) {
            return $this->skipRender();
        }
    }

    public function render()
    {
        return view('invoices.show')->layoutData([
            'title' => __('invoices.invoice', ['id' => $this->invoice->number]),
        ]);
    }

    public function downloadPDF()
    {
        return response()->streamDownload(function () {
            echo PDF::generateInvoice($this->invoice)->stream();
        }, 'invoice-' . ($this->invoice->number ?? $this->invoice->id) . '.pdf');
    }
}
