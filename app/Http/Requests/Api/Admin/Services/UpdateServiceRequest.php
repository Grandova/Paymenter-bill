<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Validation\Validator;

class UpdateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.update';

    public function rules(): array
    {
        return [
            'product_id' => 'sometimes|required|exists:products,id',
            'plan_id' => [
                'sometimes',
                'required',
                'exists:plans,id',
            ],
            'user_id' => 'sometimes|required|exists:users,id',
            /**
             * @default 1
             */
            'quantity' => 'sometimes|required|integer|min:1',
            /**
             * @default pending
             */
            'status' => 'sometimes|required|in:pending,active,cancelled,suspended',
            'expires_at' => 'sometimes|nullable|date|after_or_equal:today',
            'suspend_hold_until' => 'sometimes|nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => 'sometimes|required|string|exists:currencies,code',
            'price' => 'sometimes|required|numeric|min:0',
            'coupon_id' => 'sometimes|nullable|exists:coupons,id',
            'subscription_id' => 'sometimes|nullable|string|max:255',
            'order_id' => 'sometimes|nullable|exists:orders,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->exists('product_id') && !$this->exists('plan_id') && !$this->exists('currency_code')) {
                return;
            }

            $service = $this->route('service');
            $productId = $this->input('product_id', $service->product_id);
            $planId = $this->input('plan_id', $service->plan_id);
            $currencyCode = $this->input('currency_code', $service->currency_code);
            $plan = Plan::whereKey($planId)
                ->where('priceable_type', Product::class)
                ->where('priceable_id', $productId)
                ->first();

            if (!$plan) {
                $validator->errors()->add('plan_id', __('The selected plan does not belong to the specified product.'));
            } elseif ($plan->type !== 'free' && !$plan->prices()->where('currency_code', $currencyCode)->exists()) {
                $validator->errors()->add('plan_id', __('The selected plan is not available in the specified currency.'));
            }
        });
    }
}
