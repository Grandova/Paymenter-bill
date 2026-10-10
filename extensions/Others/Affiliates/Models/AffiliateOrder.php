<?php

namespace Paymenter\Extensions\Others\Affiliates\Models;

use App\Helpers\ExtensionHelper;
use App\Models\Order;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AffiliateOrder extends Model
{
    protected $table = 'ext_affiliate_orders';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'affiliate_id',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the earnings made from this order
     *
     * @return string
     */
    public function earnings(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $earnings = [];

                /** @var Collection */
                $invoices = $this->order->invoices->where('status', 'paid')->with('currency')->get();
                $rewards = DB::table('ext_affiliate_rewards')
                    ->whereIn('invoice_id', $invoices->pluck('id'))
                    ->get()
                    ->keyBy('invoice_id');
                $extension = ExtensionHelper::getExtension('other', 'Affiliates');
                $reward_percentage = $this->affiliate->reward ?? $extension->config('default_reward');

                $invoices->each(function ($invoice) use (&$earnings, $reward_percentage, $rewards) {
                    if (!isset($earnings[$invoice->currency->name])) {
                        $earnings[$invoice->currency->name] = 0;
                    }
                    $earnings[$invoice->currency->name] += $rewards->get($invoice->id)?->amount ?? $invoice->total * $reward_percentage / 100;
                });

                foreach ($earnings as $currency => $total) {
                    // Round to 2 decimal places
                    $earnings[$currency] = round($total, 2);
                }

                return $earnings;
            },
        );
    }
}
