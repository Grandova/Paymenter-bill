<?php

namespace Paymenter\Extensions\Others\Affiliates\Listeners;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;

class RewardAffiliate
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        /**
         * @var Invoice $invoice
         */
        $invoice = $event->invoice;

        $serviceItem = $invoice->items()->where('reference_type', Service::class)->first();
        $service = $serviceItem?->reference;
        if (!$service) {
            $upgradeItem = $invoice->items()->where('reference_type', ServiceUpgrade::class)->first();
            $service = $upgradeItem?->reference?->service;
        }
        $order = $service?->order;
        if (!$order) {
            return;
        }
        $referral = AffiliateOrder::where('order_id', $order->id)->first();

        if (!$referral) {
            return;
        }

        /**
         * @var Affiliate $affiliate
         */
        $affiliate = $referral->affiliate;
        if (!$affiliate->enabled) {
            return;
        }

        $extension = ExtensionHelper::getExtension('other', 'Affiliates');
        $reward_percentage = $affiliate->reward ?? $extension->config('default_reward');
        $reward_amount = round($invoice->total * $reward_percentage / 100, 2);
        if ($reward_amount <= 0) {
            return;
        }

        DB::transaction(function () use ($affiliate, $invoice, $reward_amount) {
            $inserted = DB::table('ext_affiliate_rewards')->insertOrIgnore([
                'affiliate_id' => $affiliate->id,
                'invoice_id' => $invoice->id,
                'amount' => $reward_amount,
                'currency_code' => $invoice->currency_code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$inserted) {
                return;
            }

            $credits = $affiliate->user->credits()
                ->where('currency_code', $invoice->currency_code)
                ->lockForUpdate()
                ->first();

            if ($credits) {
                $credits->amount = number_format(((int) round((float) $credits->amount * 100) + (int) round($reward_amount * 100)) / 100, 2, '.', '');
                $credits->recordAs('affiliate_reward', __('account.affiliate_reward', ['invoice' => $invoice->number]), $invoice)->save();
            } else {
                $affiliate->user->credits()->make([
                    'amount' => $reward_amount,
                    'currency_code' => $invoice->currency_code,
                ])->recordAs('affiliate_reward', __('account.affiliate_reward', ['invoice' => $invoice->number]), $invoice)->save();
            }
        });
    }
}
