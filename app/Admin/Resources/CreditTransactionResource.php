<?php

namespace App\Admin\Resources;

use App\Models\CreditTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditTransactionResource extends Resource
{
    protected static ?int $navigationSort = 50;

    protected static ?string $model = CreditTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-history-line';

    public static function getModelLabel(): string
    {
        return __('account.credit_history');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Customers and finance');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission('admin.credits.viewAny') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'currency', 'actor']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('user.name')->label(__('User'))->searchable(['first_name', 'last_name', 'email'])->sortable(),
                TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state) => __('account.credit_transaction_types.' . $state))->badge(),
                TextColumn::make('formattedAmount')->label(__('account.change_amount')),
                TextColumn::make('formattedBalanceBefore')->label(__('account.balance_before')),
                TextColumn::make('formattedBalanceAfter')->label(__('account.balance_after')),
                TextColumn::make('description')->label(__('Description'))->searchable(),
                TextColumn::make('actor.name')->label(__('account.operator'))->placeholder(__('System')),
            ])
            ->filters([
                SelectFilter::make('user')->relationship('user', 'email')->searchable(),
                SelectFilter::make('type')->options(fn (): array => CreditTransaction::query()->distinct()->orderBy('type')->pluck('type', 'type')->mapWithKeys(fn (string $type): array => [$type => __('account.credit_transaction_types.' . $type)])->all()),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '>=', $date . ' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '<=', $date . ' 23:59:59'))),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => CreditTransactionResource\Pages\ListCreditTransactions::route('/'),
        ];
    }
}
