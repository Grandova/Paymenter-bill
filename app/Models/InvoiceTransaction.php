<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Observers\InvoiceTransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([InvoiceTransactionObserver::class])]
class InvoiceTransaction extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'invoice_id',
        'gateway_id',
        'amount',
        'refunded_amount',
        'credited_amount',
        'fee',
        'transaction_id',
        'status',
        'is_credit_transaction',
        'credited_to_balance',
        'applied_to_invoice',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'credited_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'status' => InvoiceTransactionStatus::class,
        'credited_to_balance' => 'boolean',
        'applied_to_invoice' => 'boolean',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    /**
     * Amount that is still refundable for this transaction.
     */
    public function refundableAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount - $this->refunded_amount - $this->credited_amount
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     */
    public function formattedFee(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->fee, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     */
    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted refunded amount of the invoice.
     */
    public function formattedRefundedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->refunded_amount, 'currency' => $this->invoice->currency])
        );
    }

    public function formattedCreditedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->credited_amount, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted refundable amount of the invoice.
     */
    public function formattedRefundableAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->refundable_amount, 'currency' => $this->invoice->currency])
        );
    }
}
