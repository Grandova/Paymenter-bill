<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CurrencyResource\Pages\EditCurrency;
use App\Admin\Resources\CurrencyResource\Pages\ListCurrencies;
use App\Models\Currency;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-money-dollar-circle-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-money-dollar-circle-fill';

    public static function getModelLabel(): string
    {
        return __('Currency');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('code', 'CNY');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('Code'))
                    ->required()
                    ->maxLength(3)
                    ->disabledOn('edit')
                    ->unique(static::getModel(), 'code', ignoreRecord: true)
                    ->placeholder(__('Enter the currency code')),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->helperText(__('Display name for customers, e.g., Chinese Yuan'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('Enter the currency name')),
                TextInput::make('prefix')
                    ->label(__('Prefix'))
                    ->maxLength(10)
                    ->placeholder(__('Enter the currency prefix')),
                TextInput::make('suffix')
                    ->label(__('Suffix'))
                    ->maxLength(10)
                    ->placeholder(__('Enter the currency suffix')),
                Select::make('format')
                    ->label(__('Format'))
                    ->options([
                        '1.000,00' => '1.000,00',
                        '1,000.00' => '1,000.00',
                        '1 000,00' => '1 000,00',
                        '1 000.00' => '1 000.00',
                    ])
                    ->default('1.000,00'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prefix')
                    ->label(__('Prefix'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('suffix')
                    ->label(__('Suffix'))
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
