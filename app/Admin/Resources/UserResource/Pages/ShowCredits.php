<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Resources\UserResource;
use App\Models\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShowCredits extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'credits';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coin-line';

    public static function getNavigationLabel(): string
    {
        return __('Credits');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('currency.code')
            ->columns([
                TextColumn::make('currency.code'),
                TextColumn::make('formattedAmount')->label(__('Formatted Amount')),
            ])
            ->filters([])
            ->headerActions([
                Action::make('adjustBalance')
                    ->label(__('account.adjust_balance'))
                    ->icon('ri-scales-line')
                    ->visible(fn () => auth()->user()->hasPermission('admin.credits.update'))
                    ->form([
                        Select::make('currency_code')
                            ->label(__('Currency'))
                            ->options(Currency::query()->pluck('code', 'code'))
                            ->required(),
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->required()
                            ->step('0.01')
                            ->helperText(__('account.adjustment_amount_hint')),
                        Textarea::make('reason')
                            ->label(__('account.adjustment_reason'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        DB::transaction(function () use ($data): void {
                            $user = $this->getOwnerRecord()->newQuery()->lockForUpdate()->findOrFail($this->getOwnerRecord()->id);
                            $credits = $user->credits()
                                ->where('currency_code', $data['currency_code'])
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();
                            $balance = $credits->sum(fn ($credit) => (int) round((float) $credit->amount * 100));
                            $change = (int) round((float) $data['amount'] * 100);

                            if ($change === 0) {
                                throw ValidationException::withMessages(['amount' => __('account.adjustment_nonzero')]);
                            }
                            if ($balance + $change < 0) {
                                throw ValidationException::withMessages(['amount' => __('account.insufficient_credit_balance')]);
                            }

                            if ($change > 0 && $credits->isNotEmpty()) {
                                $credit = $credits->first();
                                $amount = (int) round((float) $credit->amount * 100) + $change;
                                $credit->amount = number_format($amount / 100, 2, '.', '');
                                $credit->recordAs('adjustment', $data['reason'])->save();
                            } elseif ($change < 0) {
                                $remaining = abs($change);
                                foreach ($credits as $credit) {
                                    $amount = (int) round((float) $credit->amount * 100);
                                    $deduction = min($amount, $remaining);
                                    if ($deduction > 0) {
                                        $credit->amount = number_format(($amount - $deduction) / 100, 2, '.', '');
                                        $credit->recordAs('adjustment', $data['reason'])->save();
                                        $remaining -= $deduction;
                                    }
                                    if ($remaining === 0) {
                                        break;
                                    }
                                }
                            } elseif ($credits->isEmpty()) {
                                $user->credits()->make([
                                    'currency_code' => $data['currency_code'],
                                    'amount' => number_format($change / 100, 2, '.', ''),
                                ])->recordAs('adjustment', $data['reason'])->save();
                            }
                        });

                        Notification::make()->title(__('account.adjust_balance'))->success()->send();
                    }),
            ]);
    }
}
