<?php

namespace App\Admin\Resources\CouponResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Services');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Service'))
            ->pluralModelLabel(__('Services'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('order.user.name')->label(__('User')),
                TextColumn::make('product.name')->label(__('Product')),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->url(fn ($record) => ServiceResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
