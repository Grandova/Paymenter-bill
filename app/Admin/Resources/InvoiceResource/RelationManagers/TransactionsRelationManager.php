<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected function canModifyTransactions(): bool
    {
        $invoice = Invoice::query()->find($this->getOwnerRecord()->id);

        return $invoice
            && !in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED], true)
            && !$invoice->transactions()->whereIn('status', [
                InvoiceTransactionStatus::Succeeded->value,
                InvoiceTransactionStatus::Processing->value,
            ])->exists()
            && (!config('settings.immutable_invoices_enabled') || $invoice->status === Invoice::STATUS_PENDING);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('gateway.name')
                    ->label(__('Gateway'))
                    ->relationship('gateway', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Select the gateway')),
                TextInput::make('transaction_id')
                    ->label(__('Transaction ID')),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->required(),
                TextInput::make('fee')
                    ->numeric()
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->label(__('Fee')),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Transactions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Transaction'))
            ->pluralModelLabel(__('Transactions'))
            ->recordTitleAttribute('transaction_id')
            ->columns([
                TextColumn::make('gateway.name')
                    ->label(__('Gateway'))
                    ->state(fn (InvoiceTransaction $record) => $record->credited_to_balance ? __('invoices.credited_to_account') : $record->gateway?->name),
                TextColumn::make('transaction_id'),
                TextColumn::make('formattedAmount')->label(__('Amount')),
                TextColumn::make('formattedRefundedAmount')->label(__('invoices.refunded_amount')),
                TextColumn::make('formattedCreditedAmount')->label(__('invoices.credited_amount')),
                TextColumn::make('formattedFee')->label(__('Fee')),
                TextColumn::make('created_at'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canModifyTransactions())
                    ->before(function (CreateAction $action): void {
                        if ($this->canModifyTransactions()) {
                            return;
                        }

                        Notification::make()->title(__('invoices.invoice_edit_blocked'))->danger()->send();
                        $action->halt();
                    }),
            ])
            ->recordActions([
                Action::make('refund')
                    ->label(__('invoices.refund'))
                    ->icon('heroicon-o-backward')
                    ->color('warning')
                    ->modalHeading(fn (InvoiceTransaction $record): string => __('invoices.refund_transaction', ['id' => $record->transaction_id ?? $record->id]))
                    ->modalDescription(fn (InvoiceTransaction $record): string => __('invoices.refundable_amount', ['amount' => $record->formattedRefundableAmount]))
                    ->form([
                        TextInput::make('amount')
                            ->label(__('invoices.amount'))
                            ->numeric()
                            ->prefix(fn (InvoiceTransaction $record): ?string => $record->invoice?->currency->prefix)
                            ->suffix(fn (InvoiceTransaction $record): ?string => $record->invoice?->currency->suffix)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->required()
                            ->rules([
                                fn (InvoiceTransaction $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if ((float) $value <= 0) {
                                        $fail(__('invoices.refund_amount_positive'));
                                    }
                                    if ((float) $value > $record->refundable_amount) {
                                        $fail(__('invoices.refund_amount_exceeds_refundable', ['max' => $record->formattedRefundableAmount]));
                                    }
                                },
                            ]),
                        Toggle::make('refund_via_gateway')
                            ->label(__('invoices.refund_via_gateway'))
                            ->default(false)
                            ->visible(fn (InvoiceTransaction $record): bool => filled($record->transaction_id) && $record->gateway && $record->gateway->extension && ExtensionHelper::hasFunction($record->gateway, 'supportsRefunds') && ExtensionHelper::hasFunction($record->gateway, 'refund')),
                    ])
                    ->action(function (InvoiceTransaction $record, array $data, Action $action): void {
                        try {
                            if (!empty($data['refund_via_gateway']) && $record->gateway) {
                                ExtensionHelper::refund($record, (float) $data['amount']);
                            } else {
                                ExtensionHelper::refundLocally($record, (float) $data['amount']);
                            }

                            $this->notifyRefundResult(true);

                            $action->success();
                        } catch (\Exception $e) {
                            $this->notifyRefundResult(false, $e->getMessage());

                            $action->halt();
                        }
                    })
                    ->visible(
                        fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded
                            && !$record->credited_to_balance &&
                            $record->refundable_amount > 0 &&
                            Auth::user()->can('update', $record)
                    )
                    ->modalSubmitAction(fn (Action $action) => $action->label(__('invoices.refund'))),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canModifyTransactions())
                    ->before(function (InvoiceTransaction $record, DeleteAction $action): void {
                        if ($this->canModifyTransactions()) {
                            return;
                        }

                        Notification::make()->title(__('invoices.invoice_edit_blocked'))->danger()->send();
                        $action->cancel();
                    })
                    ->disabled(fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded || $record->credited_to_balance)
                    ->tooltip(fn (InvoiceTransaction $record): ?string => $record->status === InvoiceTransactionStatus::Succeeded || $record->credited_to_balance
                        ? __('invoices.successful_transaction_delete_blocked')
                        : null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canModifyTransactions())
                        ->before(function (DeleteBulkAction $action, $records): void {
                            if (!$this->canModifyTransactions() || $records->contains(fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded || $record->credited_to_balance)) {
                                Notification::make()
                                    ->title(__('invoices.invoice_edit_blocked'))
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    private function notifyRefundResult(bool $success, ?string $errorMessage = null): void
    {
        Notification::make()
            ->title(__($success ? 'invoices.refund_success' : 'invoices.refund_failed'))
            ->when(!$success, fn (Notification $notification) => $notification->body($errorMessage))
            ->{$success ? 'success' : 'danger'}()
            ->send();
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
