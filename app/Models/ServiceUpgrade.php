<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\ServiceUpgradeObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceUpgradeObserver::class])]
class ServiceUpgrade extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $guarded = [];

    protected $casts = [
        'stock_reserved' => 'boolean',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function configs()
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    public function calculateProratedAmount($oldItem, $newItem): Price
    {
        if (
            !$newItem ||
            ($oldItem && (
                (method_exists($newItem, 'is') && $newItem->is($oldItem)) ||
                (isset($oldItem->id, $newItem->id) && $oldItem->id === $newItem->id)
            ))
        ) {
            return $this->makePrice();
        }

        $plan = $this->service->plan;
        $newPrice = $newItem->price(null, $plan->billing_period, $plan->billing_unit, $this->service->currency_code)->price;

        if (!$this->service->expires_at) {
            return $this->makePrice($newPrice);
        }

        $billingPeriodSeconds = $this->getBillingPeriodSeconds();
        $remainingSeconds = $this->getRemainingSeconds();
        $priceDifference = $newPrice - $this->resolveOldItemPrice($oldItem);
        $total = $billingPeriodSeconds > 0 ? ($priceDifference / $billingPeriodSeconds) * $remainingSeconds : $priceDifference;

        return $this->makePrice($total);
    }

    public function calculatePrice(): Price
    {
        $total = $this->calculateProratedAmount($this->service->product, $this->product)->price;

        foreach ($this->configs as $config) {
            if ($configValue = $config->configValue) {
                $oldPrice = $this->service->configs->where('config_option_id', $config->config_option_id)->first();
                $total += $this->calculateProratedAmount($oldPrice?->configValue, $configValue)->price;
            }
        }

        // Cap refunds to what was actually paid when coupon exists
        if ($total < 0 && $this->service->coupon_id) {
            $total = max($total, -$this->getMaxRefundAmount());
        }

        return $this->makePrice($total);
    }

    protected function resolveOldItemPrice($oldItem): float
    {
        if (empty($oldItem)) {
            return 0;
        }

        $price = $oldItem->price(
            null,
            $this->service->plan->billing_period,
            $this->service->plan->billing_unit,
            $this->service->currency_code
        )->price ?? 0;

        return (float) $price;
    }

    protected function makePrice(float $amount = 0): Price
    {
        return new Price([
            'price' => $amount,
            'currency' => $this->service->currency,
        ]);
    }

    protected function getBillingPeriodSeconds(): int
    {
        $plan = $this->service->plan;

        return match ($plan->billing_unit) {
            'hour' => $plan->billing_period * 3600,
            'day' => $plan->billing_period * 86400,
            'week' => $plan->billing_period * 7 * 86400,
            'month' => $plan->billing_period * 30 * 86400,
            'year' => $plan->billing_period * 365 * 86400,
            default => 0,
        };
    }

    protected function getRemainingSeconds(): int
    {
        if (!$this->service->expires_at) {
            return 0;
        }
        $billingPeriodSeconds = $this->getBillingPeriodSeconds();

        $remainingSeconds = $this->service->expires_at->getTimestamp() - Carbon::now()->getTimestamp();

        return min(max(0, $remainingSeconds), $billingPeriodSeconds);
    }

    public function getMaxRefundAmount(): float
    {
        // We don't refund if service has no due date (one-time or free plans)
        if (!$this->service->expires_at) {
            return 0;
        }
        $billingPeriodSeconds = $this->getBillingPeriodSeconds();
        $remainingSeconds = $this->getRemainingSeconds();
        $paidAmount = (float) $this->service->calculatePrice();

        return $billingPeriodSeconds > 0 ? ($paidAmount / $billingPeriodSeconds) * $remainingSeconds : $paidAmount;
    }
}
