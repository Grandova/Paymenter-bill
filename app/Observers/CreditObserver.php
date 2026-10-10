<?php

namespace App\Observers;

use App\Models\Credit;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreditObserver
{
    public function created(Credit $credit): void
    {
        $this->record($credit, (int) round((float) $credit->amount * 100));
    }

    public function updated(Credit $credit): void
    {
        $oldAmount = (int) round((float) $credit->getRawOriginal('amount') * 100);
        $newAmount = (int) round((float) $credit->amount * 100);

        if ((int) $credit->getRawOriginal('user_id') !== (int) $credit->user_id || $credit->getRawOriginal('currency_code') !== $credit->currency_code) {
            $this->record($credit, -$oldAmount, (int) $credit->getRawOriginal('user_id'), $credit->getRawOriginal('currency_code'));
            $this->record($credit, $newAmount);

            return;
        }

        $this->record($credit, $newAmount - $oldAmount);
    }

    public function deleted(Credit $credit): void
    {
        $this->record($credit, -(int) round((float) $credit->amount * 100));
    }

    private function record(Credit $credit, int $change, ?int $userId = null, ?string $currencyCode = null): void
    {
        if ($change === 0) {
            return;
        }

        $userId ??= $credit->user_id;
        $currencyCode ??= $credit->currency_code;
        $balanceAfter = (int) round((float) DB::table('credits')
            ->where('user_id', $userId)
            ->where('currency_code', $currencyCode)
            ->sum('amount') * 100);
        $context = $credit->transactionContext();
        $adminActor = Auth::user()?->role_id ? Auth::id() : null;

        CreditTransaction::create([
            'user_id' => $userId,
            'currency_code' => $currencyCode,
            'amount' => number_format($change / 100, 2, '.', ''),
            'balance_before' => number_format(($balanceAfter - $change) / 100, 2, '.', ''),
            'balance_after' => number_format($balanceAfter / 100, 2, '.', ''),
            'type' => $context['type'] ?? 'adjustment',
            'description' => $context['description'] ?? ($adminActor ? __('account.credit_adjustment') : __('account.balance_change')),
            'reference_type' => isset($context['reference']) ? $context['reference']->getMorphClass() : null,
            'reference_id' => ($context['reference'] ?? null)?->getKey(),
            'actor_id' => $adminActor,
        ]);
    }
}
