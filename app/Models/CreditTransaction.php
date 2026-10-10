<?php

namespace App\Models;

use App\Classes\Price;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CreditTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->currency])
        );
    }

    public function formattedBalanceBefore(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->balance_before, 'currency' => $this->currency])
        );
    }

    public function formattedBalanceAfter(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->balance_after, 'currency' => $this->currency])
        );
    }
}
