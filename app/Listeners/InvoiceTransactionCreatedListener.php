<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created;
use App\Events\InvoiceTransaction\Updated;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;

class InvoiceTransactionCreatedListener
{
    /**
     * Handle the event.
     */
    public function handle(Created|Updated $event): void
    {
        DB::transaction(function () use ($event) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($event->invoiceTransaction->invoice_id);
            $transaction = InvoiceTransaction::query()->lockForUpdate()->findOrFail($event->invoiceTransaction->id);

            if ($transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->credited_to_balance || $transaction->credited_amount > 0 || $transaction->applied_to_invoice) {
                return;
            }

            if (in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true)) {
                if ($transaction->is_credit_transaction || !config('settings.credits_enabled')) {
                    return;
                }

                $amountToCredit = (int) round(((float) $transaction->amount - (float) $transaction->refunded_amount) * 100);
                if ($amountToCredit <= 0) {
                    return;
                }

                $user = $invoice->user()->lockForUpdate()->firstOrFail();
                $credit = $user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                if ($credit) {
                    $credit->amount = number_format(((int) round((float) $credit->amount * 100) + $amountToCredit) / 100, 2, '.', '');
                    $credit->recordAs('payment_credit', __('account.payment_credit', ['invoice' => $invoice->number]), $invoice)->save();
                } else {
                    $user->credits()->make([
                        'currency_code' => $invoice->currency_code,
                        'amount' => $amountToCredit / 100,
                    ])->recordAs('payment_credit', __('account.payment_credit', ['invoice' => $invoice->number]), $invoice)->save();
                }

                $transaction->credited_amount = number_format($amountToCredit / 100, 2, '.', '');
                $transaction->credited_to_balance = $amountToCredit === (int) round((float) $transaction->amount * 100);
                $transaction->saveQuietly();

                return;
            }

            $transaction->applied_to_invoice = true;
            $transaction->saveQuietly();
            $invoice->load('transactions');
            if ($invoice->remaining <= 0) {
                $overpayment = (int) round(abs(min(0, $invoice->remaining)) * 100);
                if ($overpayment > 0 && !config('settings.credits_enabled')) {
                    $overpayment = 0;
                }
                if ($overpayment > 0) {
                    $user = $invoice->user()->lockForUpdate()->firstOrFail();
                    $credit = $user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                    if ($credit) {
                        $credit->amount = number_format(((int) round((float) $credit->amount * 100) + $overpayment) / 100, 2, '.', '');
                        $credit->recordAs('overpayment', __('account.overpayment_credit', ['invoice' => $invoice->number]), $invoice)->save();
                    } else {
                        $user->credits()->make([
                            'currency_code' => $invoice->currency_code,
                            'amount' => $overpayment / 100,
                        ])->recordAs('overpayment', __('account.overpayment_credit', ['invoice' => $invoice->number]), $invoice)->save();
                    }

                    $transaction->credited_amount = number_format((float) $transaction->credited_amount + $overpayment / 100, 2, '.', '');
                    $transaction->saveQuietly();
                    $invoice->load('transactions');
                }

                $invoice->update(['status' => Invoice::STATUS_PAID]);
            }
        });
    }
}
