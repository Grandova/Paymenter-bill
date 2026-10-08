<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Resources\GatewayResource;
use App\Admin\Resources\UserResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowBillingAgreements extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'billingAgreements';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-bank-card-line';

    public static function getNavigationLabel(): string
    {
        return __('Billing Agreements');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name')),
                TextColumn::make('external_reference')
                    ->label(__('External Reference')),
                TextColumn::make('gateway.name')
                    ->url(fn ($record) => GatewayResource::getUrl('edit', ['record' => $record->gateway_id]))
                    ->label(__('Gateway')),
            ]);
    }
}
