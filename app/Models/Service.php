<?php

namespace App\Models;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Traits\HasProperties;
use App\Observers\ServiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceObserver::class])]
class Service extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'order_id',
        'product_id',
        'plan_id',
        'quantity',
        'price',
        'expires_at',
        'subscription_id',
        'status',
        'coupon_id',
        'user_id',
        'currency_code',
        'billing_agreement_id',
        'auto_renew',
        'suspend_hold_until',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'suspend_hold_until' => 'date',
        'renewal_count' => 'integer',
    ];

    /**
     * Get the order that owns the service.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the coupon that owns the service.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get the currency corresponding to the service.
     */
    public function currency()
    {
        return $this->hasOne(Currency::class, 'code', 'currency_code');
    }

    /**
     * Get the user that owns the service.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Price of the service.
     *
     * @return string
     */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price * $this->quantity, 'currency' => $this->currency])
        );
    }

    public function label(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?: $this->baseLabel
        );
    }

    public function baseLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product->name . ' #' . $this->id
        );
    }

    /**
     * Get the description for the next invoice item.
     */
    public function description(): Attribute
    {
        if ($this->plan->type == 'free' || $this->plan->type == 'one-time') {
            return Attribute::make(
                get: fn () => $this->product->name
            );
        }
        $date = $this->expires_at ?? now();
        $endDate = $this->addBillingPeriod($date->copy());

        return Attribute::make(
            get: fn () => $this->product->name . ' (' . $date->translatedFormat(__('general.date_format')) . ' - ' . $endDate->translatedFormat(__('general.date_format')) . ')'
        );
    }

    /**
     * Calculate next due date.
     */
    public function calculateNextDueDate()
    {
        if ($this->plan->type == 'one-time' || $this->plan->type == 'free') {
            return null;
        }
        if (!$this->expires_at || $this->status != self::STATUS_ACTIVE) {
            // Make sure that if a service is being renewed after suspension or pending, we use the current date as base
            $date = now();
        } else {
            $date = $this->expires_at;
        }

        return $this->addBillingPeriod($date);
    }

    private function addBillingPeriod($date)
    {
        return match ($this->plan->billing_unit) {
            'hour' => $date->addHours($this->plan->billing_period),
            'day' => $date->addDays($this->plan->billing_period),
            'week' => $date->addWeeks($this->plan->billing_period),
            'month' => $date->addMonthsNoOverflow($this->plan->billing_period),
            'year' => $date->addYearsNoOverflow($this->plan->billing_period),
        };
    }

    /**
     * Get the product corresponding to the service.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the plan corresponding to the service.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the service's configurations.
     */
    public function configs()
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    /**
     * Get invoiceItems
     */
    public function invoiceItems()
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * Get invoices
     */
    public function invoices()
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'reference_id', 'id', 'id', 'invoice_id')->where('reference_type', Service::class);
    }

    public function removePendingRenewalInvoiceItems(string $reason): void
    {
        $invoices = $this->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->orderBy('invoices.id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $invoice->items()
                ->where('reference_type', self::class)
                ->where('reference_id', $this->id)
                ->delete();

            if (!$invoice->items()->exists()) {
                $invoice->update([
                    'status' => Invoice::STATUS_CANCELLED,
                    'cancellation_reason' => $reason,
                ]);
            }
        }

        ServiceUpgrade::where('service_id', $this->id)
            ->where('type', 'renewal_cycle')
            ->where('status', ServiceUpgrade::STATUS_PENDING)
            ->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);

        ServiceUpgrade::where('service_id', $this->id)
            ->where('type', '!=', 'renewal_cycle')
            ->where('status', ServiceUpgrade::STATUS_PENDING)
            ->get()
            ->each(function (ServiceUpgrade $upgrade) use ($reason) {
                if ($upgrade->invoice?->status === Invoice::STATUS_PENDING) {
                    $upgrade->invoice->update([
                        'status' => Invoice::STATUS_CANCELLED,
                        'cancellation_reason' => $reason,
                    ]);
                } else {
                    $upgrade->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);
                }
            });
    }

    /**
     * Get cancellation requests
     */
    public function cancellation()
    {
        return $this->hasOne(ServiceCancellation::class);
    }

    public function cancellable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status !== 'cancelled' && $this->plan->type != 'free' && $this->plan->type != 'one-time' && !$this->cancellation?->exists()
        );
    }

    public function upgradable(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->productUpgrades()->count() > 0 || $this->product->upgradableConfigOptions()->count() > 0) && $this->status == 'active' && !$this->cancellation()->exists() && $this->upgrade->where('status', ServiceUpgrade::STATUS_PENDING)->count() == 0
        );
    }

    public function productUpgrades()
    {
        return $this->product->upgrades->filter(function ($product) {
            // Check stock
            if ($product->stock !== null && ($product->stock - $this->quantity) < 0) {
                return null;
            }
            $plan = $product->plans()
                ->where('billing_unit', $this->plan->billing_unit)
                ->where('billing_period', $this->plan->billing_period)
                ->where(function ($query) {
                    $query->where('type', 'free')
                        ->orWhereHas('prices', fn ($query) => $query->where('currency_code', $this->currency_code));
                })
                ->get();
            // Only get the upgrades that have the exact same billing cycle as the service
            if ($plan->count() > 0) {
                $product->plan = $plan->first();

                return $product;
            }

            return null;
        });
    }

    public function calculatePrice()
    {
        // Calculate the price based on the plan and config options
        $price = $this->plan->price($this->currency_code)->price;

        $this->configs->each(function ($config) use (&$price) {
            $configValue = $config->configValue;
            if ($configValue) {
                $price += $configValue->price(null, $this->plan->billing_period, $this->plan->billing_unit, $this->currency_code)->price;
            }
        });

        // Add coupon discount if applicable
        if ($this->coupon && ($this->coupon->products->isEmpty() || $this->coupon->products->contains('id', $this->product_id))) {
            $paidInvoices = $this->invoices()->where('status', 'paid')->count();
            $renewals = max((int) $this->renewal_count, max(0, $paidInvoices - 1));
            $invoices = $renewals + 2;
            // If it already used for the recurring period, do not apply the discount
            if ($this->coupon->recurring === 0 || $invoices <= ($this->coupon->recurring ?? 1)) {
                $discount = $this->coupon->calculateDiscount($price);
                $price -= $discount;
            }
        }

        $price = (new Price([
            'price' => $price,
            'currency' => $this->currency,
        ], apply_exclusive_tax: true, tax: Settings::tax($this->user)))->price;

        return number_format($price, 2, '.', '');
    }

    public function upgrade()
    {
        return $this->hasMany(ServiceUpgrade::class);
    }

    public function billingAgreement()
    {
        return $this->belongsTo(BillingAgreement::class, 'billing_agreement_id');
    }
}
