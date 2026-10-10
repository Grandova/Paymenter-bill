<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\CreditObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([CreditObserver::class])]
class Credit extends Model implements Auditable
{
    use Traits\Auditable;

    private array $ledgerContext = [];

    protected $fillable = [
        'currency_code',
        'amount',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function recordAs(string $type, ?string $description = null, ?Model $reference = null): static
    {
        $this->ledgerContext = compact('type', 'description', 'reference');

        return $this;
    }

    public function transactionContext(): array
    {
        return $this->ledgerContext;
    }

    public static function spend(User $user, string $currencyCode, float $amount, bool $partial = true, string $type = 'invoice_payment', ?Model $reference = null): float
    {
        $requested = (int) round($amount * 100);
        $credits = $user->credits()
            ->where('currency_code', $currencyCode)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $available = $credits->sum(fn (Credit $credit) => (int) round((float) $credit->amount * 100));

        if ($requested <= 0 || (!$partial && $available < $requested)) {
            return 0;
        }

        $spent = min($available, $requested);
        $remaining = $spent;
        foreach ($credits as $credit) {
            $balance = (int) round((float) $credit->amount * 100);
            $deduction = min($balance, $remaining);
            if ($deduction > 0) {
                $credit->amount = number_format(($balance - $deduction) / 100, 2, '.', '');
                $credit->recordAs($type, null, $reference)->save();
                $remaining -= $deduction;
            }

            if ($remaining === 0) {
                break;
            }
        }

        return $spent / 100;
    }

    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->currency])
        );
    }
}
