<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Components\UserComponent;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $cluster = InvoiceCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserComponent::make('user_id'),
                TextInput::make('number')
                    ->label(__('Invoice Number'))
                    ->helperText(__('The invoice number will be generated automatically'))
                    ->disabled(),
                DatePicker::make('created_at')
                    ->label(__('Issued At'))
                    ->required()
                    ->default(now())
                    ->placeholder(__('Select the date and time the invoice was issued')),
                DatePicker::make('due_at')
                    ->label(__('Due At'))
                    ->required()
                    ->default(now()->addDays(7))
                    ->placeholder(__('Select the date and time the invoice is due')),
                Select::make('status')
                    ->label(__('Status'))
                    ->required()
                    ->options([
                        'paid' => __('Paid'),
                        'pending' => __('Pending'),
                        'draft' => __('Draft'),
                        'cancelled' => __('Cancelled'),
                    ])
                    ->default(fn (): string => config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_PENDING)
                    ->placeholder(__('Select the status of the invoice')),
                Select::make('currency_code')
                    ->label(__('Currency'))
                    ->required()
                    ->relationship('currency', 'code')
                    ->placeholder(__('Select the currency')),
                Toggle::make('send_email')
                    ->label(__('Send Email'))
                    ->hiddenOn('edit')
                    ->default(true),
                Repeater::make('items')
                    ->relationship('items')
                    ->label(__('Items'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('price')
                            ->label(__('Price'))
                            // Grab invoice currency
                            ->prefix(fn (Get $get): ?string => Currency::where('code', $get('../../currency_code'))->first()?->prefix)
                            ->suffix(fn (Get $get): ?string => Currency::where('code', $get('../../currency_code'))->first()?->suffix)
                            ->required()
                            ->numeric()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->placeholder(__('Enter the price of the product')),
                        TextInput::make('quantity')
                            ->label(__('Quantity'))
                            ->required()
                            ->numeric()
                            ->placeholder(__('Enter the quantity of the product')),
                        TextInput::make('description')
                            ->label(__('Description'))
                            ->required()
                            ->hintAction(
                                Action::make('View Service')
                                    ->url(function (Get $get) {
                                        return ServiceResource::getUrl('edit', ['record' => $get('reference_id')]);
                                    })
                                    ->label(__('View Service'))
                                    ->hidden(fn (Get $get): bool => !in_array($get('reference_type'), [Service::class, ServiceUpgrade::class]))
                            )
                            ->placeholder(__('Enter the description of the product')),
                        Hidden::make('reference_type'),
                        Hidden::make('reference_id'),
                    ]),

            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['currency', 'transactions', 'items', 'adjustmentNotes', 'user'])
            ->withExists([
                'transactions as has_in_flight_transactions' => fn (Builder $query) => $query->whereIn('status', [
                    InvoiceTransactionStatus::Succeeded->value,
                    InvoiceTransactionStatus::Processing->value,
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->searchable(true, fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    // Make first letter uppercase
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'draft' => 'gray',
                        default => 'danger',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Issued At'))
                    ->date()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label(__('Due At'))
                    ->date()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label(__('Total')),
                TextColumn::make('formattedCurrentBalance')
                    ->label(__('Remaining')),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'paid' => __('Paid'),
                        'pending' => __('Pending'),
                        'draft' => __('Draft'),
                        'cancelled' => __('Cancelled'),
                    ]),
                Filter::make('overdue')
                    ->label(__('Overdue invoices'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', Invoice::STATUS_PENDING)
                        ->whereDate('due_at', '<', today())),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Invoice $record): bool => !$record->canBeEdited() || (config('settings.immutable_invoices_enabled', false) && $record->status !== Invoice::STATUS_DRAFT)),
                EditAction::make()
                    ->visible(fn (Invoice $record): bool => $record->canBeEdited() && (!config('settings.immutable_invoices_enabled', false) || $record->status === Invoice::STATUS_DRAFT)),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        TextInput::make('cancellation_reason')
                            ->label(__('Cancellation Reason'))
                            ->required(),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        DB::transaction(function () use ($record, $data): void {
                            $invoice = Invoice::query()->lockForUpdate()->findOrFail($record->id);
                            if (!$invoice->canBeEdited() || in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true)) {
                                Notification::make()->title(__('invoices.invoice_cancel_blocked'))->danger()->send();

                                return;
                            }

                            $invoice->update([
                                'status' => Invoice::STATUS_CANCELLED,
                                'cancellation_reason' => $data['cancellation_reason'],
                            ]);
                            $invoice->cancelPendingServices();
                        });
                    })
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('update', Invoice::class)
                        && $record->canBeEdited()
                        && !in_array($record->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID])),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            TransactionsRelationManager::class,
        ];

        $relations[] = AdjustmentNotesRelationManager::class;

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            // Always use id for invoice route binding in admin
            'view' => ViewInvoice::route('/{record:id}'),
            'edit' => EditInvoice::route('/{record:id}/edit'),
        ];
    }
}
