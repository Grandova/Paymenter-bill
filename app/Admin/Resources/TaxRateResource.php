<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TaxRateResource\Pages\CreateTaxRate;
use App\Admin\Resources\TaxRateResource\Pages\EditTaxRate;
use App\Admin\Resources\TaxRateResource\Pages\ListTaxRates;
use App\Models\TaxRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxRateResource extends Resource
{
    protected static ?int $navigationSort = 150;

    protected static ?string $model = TaxRate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-wallet-3-line';

    public static function getModelLabel(): string
    {
        return __('Tax Rate');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System management');
    }

    public static function form(Schema $schema): Schema
    {
        $countries = ['all' => __('All Countries')] + array_map(fn ($country) => __($country), config('app.countries'));
        unset($countries['']);

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('Enter the name of the tax rate')),
                TextInput::make('rate')
                    ->label(__('Rate'))
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->required()
                    ->suffix('%')
                    ->placeholder(__('Enter the rate of the tax rate')),
                Select::make('country')
                    ->label(__('Country'))
                    ->required()
                    ->unique(null, 'country', ignoreRecord: true)
                    ->options($countries),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rate')
                    ->label(__('Rate'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country')
                    ->formatStateUsing(fn (string $state): string => array_map(fn ($country) => __($country), config('app.countries'))[$state] ?? __('All Countries'))
                    ->label(__('Country'))
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canAccess(): bool
    {
        return config('settings.tax_enabled') ? true && static::canViewAny() : false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxRates::route('/'),
            'create' => CreateTaxRate::route('/create'),
            'edit' => EditTaxRate::route('/{record}/edit'),
        ];
    }
}
