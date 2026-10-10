<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">
        <h4 class="text-2xl font-bold pb-3">{{ __('account.credits') }}</h4>
        @if (Auth::user()->credits->count() > 0)
        <div class="flex flex-wrap gap-4">
            @foreach (Auth::user()->credits as $credit)
            <div class="flex flex-col bg-background-secondary w-fit rounded-lg px-5 p-3 items-center gap-1">
                <h5 class="text-lg font-bold">{{ $credit->currency->code }}</h5>
                <p class="text-primary-100">{{ $credit->formattedAmount }}</p>
            </div>
            @endforeach
        </div>
        @else
        <p>{{ __('account.no_credit') }}</p>
        @endif

        <h4 class="text-xl font-bold pb-3">{{ __('account.add_credit') }}</h4>

        <form wire:submit.prevent="addCredit">
            <!-- Currency and amount -->
            <div class="grid grid-cols-2 gap-4">
                <x-form.select name="currency" :label="__('account.input.currency')" wire:model.live="currency" required>
                    @foreach(\App\Models\Currency::all() as $currency)
                    <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                    @endforeach
                </x-form.select>
                <x-form.input x-mask:dynamic="$money($input, '.', '', 2)" name="amount" type="number"
                    :label="__('account.input.amount')" :placeholder="__('account.input.amount_placeholder')"
                    wire:model.live.debounce.250ms="amount" required />

                <x-form.select name="gateway" :label="__('product.payment_method')" wire:model.live="gateway" required>
                    @foreach($gateways as $gatewayy)
                    <option value="{{ $gatewayy->id }}" wire:key="{{ $gatewayy->id }}" @if($gatewayy->id == $gateway) selected @endif>{{ $gatewayy->name }}</option>
                    @endforeach
                </x-form.select>
            </div>


            <x-button.primary type="submit" class="w-full mt-4">
                {{ __('account.add_credit') }}
            </x-button.primary>
        </form>

        <div class="mt-8">
            <h4 class="text-xl font-bold pb-3">{{ __('account.credit_history') }}</h4>
            @if ($transactions->isEmpty())
                <p>{{ __('account.no_credit_history') }}</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-neutral">
                    <table class="w-full text-left">
                        <thead class="bg-background-secondary">
                            <tr>
                                <th class="p-3">{{ __('Date') }}</th>
                                <th class="p-3">{{ __('Type') }}</th>
                                <th class="p-3">{{ __('account.change_amount') }}</th>
                                <th class="p-3">{{ __('account.balance_after') }}</th>
                                <th class="p-3">{{ __('Description') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $transaction)
                                <tr class="border-t border-neutral">
                                    <td class="p-3 whitespace-nowrap">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                    <td class="p-3 whitespace-nowrap">{{ __('account.credit_transaction_types.' . $transaction->type) }}</td>
                                    <td class="p-3 whitespace-nowrap">{{ $transaction->formattedAmount }}</td>
                                    <td class="p-3 whitespace-nowrap">{{ $transaction->formattedBalanceAfter }}</td>
                                    <td class="p-3">{{ $transaction->description ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $transactions->links() }}</div>
            @endif
        </div>
    </div>
</div>
