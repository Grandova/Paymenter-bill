<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\ServiceResource;
use App\Classes\PDF;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (!config('settings.immutable_invoices_enabled', false) && $this->record->canBeEdited()) {
            $this->redirect(InvoiceResource::getUrl('edit', ['record' => $record]));
        }
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Invoice Details'))
                    ->schema([
                        TextEntry::make('number')
                            ->label(__('Invoice Number')),
                        TextEntry::make('user.name')
                            ->label(__('User')),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'paid' => 'success',
                                'pending' => 'warning',
                                'draft' => 'gray',
                                default => 'danger',
                            })
                            ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                        TextEntry::make('currency.code')
                            ->label(__('Currency')),
                        TextEntry::make('created_at')
                            ->label(__('Issued At'))
                            ->dateTime(),
                        TextEntry::make('due_at')
                            ->label(__('Due At'))
                            ->date(),
                        TextEntry::make('formattedTotal')
                            ->label(__('Total')),
                        TextEntry::make('formattedCurrentBalance')
                            ->label(__('Remaining')),
                    ])
                    ->columns(3),
                Section::make(__('Items'))
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('description')
                                    ->label(__('Description'))
                                    ->url(fn ($record) => in_array($record->reference_type, [Service::class, ServiceUpgrade::class])
                                        ? ServiceResource::getUrl('edit', ['record' => $record->reference_type == Service::class ? $record->reference_id : $record->reference->service_id])
                                        : null)
                                    ->color(fn ($record) => in_array($record->reference_type, [Service::class, ServiceUpgrade::class]) ? 'primary' : null),
                                TextEntry::make('price')
                                    ->label(__('Price'))
                                    ->money(fn ($record) => $record->invoice->currency_code ?? config('settings.default_currency')),
                                TextEntry::make('quantity')
                                    ->label(__('Quantity')),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('Cancel Invoice'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    TextInput::make('cancellation_reason')
                        ->label(__('Cancellation Reason'))
                        ->required(),
                ])
                ->action(function (Invoice $invoice, array $data) {
                    DB::transaction(function () use ($invoice, $data): void {
                        $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                        if (!$lockedInvoice->canBeEdited() || in_array($lockedInvoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true)) {
                            Notification::make()->title(__('invoices.invoice_cancel_blocked'))->danger()->send();

                            return;
                        }

                        $lockedInvoice->update([
                            'status' => Invoice::STATUS_CANCELLED,
                            'cancellation_reason' => $data['cancellation_reason'],
                        ]);
                        $lockedInvoice->cancelPendingServices();
                    });
                })
                ->visible(fn (Invoice $invoice): bool => $invoice->canBeEdited()
                    && !in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true)),
            Action::make('pdf')
                ->label(__('Download PDF'))
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->stream();
                    }, 'invoice-' . ($invoice->number ?? $invoice->id) . '.pdf');
                }),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            TransactionsRelationManager::class,
            AdjustmentNotesRelationManager::class,
        ];
    }
}
