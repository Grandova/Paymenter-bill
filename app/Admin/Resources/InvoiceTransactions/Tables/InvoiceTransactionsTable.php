<?php

namespace App\Admin\Resources\InvoiceTransactions\Tables;

use App\Admin\Resources\InvoiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Models\InvoiceTransaction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.currency', 'invoice.user', 'gateway']))
            ->columns([
                TextColumn::make('invoice_id')
                    ->url(fn (InvoiceTransaction $record): string => InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record->invoice_id]))
                    ->numeric()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invoice.user.email')
                    ->label(__('User'))
                    ->searchable(),
                TextColumn::make('gateway.name')
                    ->state(fn (InvoiceTransaction $record) => $record->credited_to_balance ? __('invoices.credited_to_account') : $record->gateway?->name)
                    ->sortable(),
                TextColumn::make('formattedAmount')
                    ->label(__('Amount'))
                    ->sortable(['amount']),
                TextColumn::make('formattedRefundedAmount')
                    ->label(__('invoices.refunded_amount')),
                TextColumn::make('formattedCreditedAmount')
                    ->label(__('invoices.credited_amount')),
                TextColumn::make('formattedFee')
                    ->label(__('Fee'))
                    ->sortable(['fee']),
                TextColumn::make('transaction_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (InvoiceTransaction $record) => match ($record->status) {
                        InvoiceTransactionStatus::Succeeded => 'success',
                        InvoiceTransactionStatus::Processing => 'warning',
                        InvoiceTransactionStatus::Failed => 'danger',
                        default => null,
                    })
                    ->formatStateUsing(fn (InvoiceTransactionStatus $state): string => __(ucfirst($state->value)))
                    ->label(__('Status')),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    InvoiceTransactionStatus::Succeeded->value => __('Succeeded'),
                    InvoiceTransactionStatus::Processing->value => __('Processing'),
                    InvoiceTransactionStatus::Failed->value => __('Failed'),
                ]),
                SelectFilter::make('gateway')->relationship('gateway', 'name')->searchable()->preload(),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '>=', $date . ' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '<=', $date . ' 23:59:59'))),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
