<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\UserResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShowInvoices extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    public static function getNavigationLabel(): string
    {
        return __('Invoices');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('formattedTotal')->label(__('Total')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    // Make first letter uppercase
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'paid' => __('Paid'),
                        'pending' => __('Pending'),
                        'cancelled' => __('Cancelled'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record])),
            ]);
    }
}
