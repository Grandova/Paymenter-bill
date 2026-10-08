<?php

namespace App\Admin\Resources\CategoryResource\RelationManagers;

use App\Admin\Resources\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Product'))
            ->pluralModelLabel(__('Products'))
            ->reorderable('sort')
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
