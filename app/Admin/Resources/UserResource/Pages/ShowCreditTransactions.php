<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Resources\UserResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowCreditTransactions extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'creditTransactions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-history-line';

    public static function getNavigationLabel(): string
    {
        return __('account.credit_history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state) => __('account.credit_transaction_types.' . $state))->badge(),
                TextColumn::make('formattedAmount')->label(__('account.change_amount')),
                TextColumn::make('formattedBalanceBefore')->label(__('account.balance_before')),
                TextColumn::make('formattedBalanceAfter')->label(__('account.balance_after')),
                TextColumn::make('description')->label(__('Description')),
                TextColumn::make('actor.name')->label(__('account.operator'))->placeholder(__('System')),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
