<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CouponResource\Pages\CreateCoupon;
use App\Admin\Resources\CouponResource\Pages\EditCoupon;
use App\Admin\Resources\CouponResource\Pages\ListCoupons;
use App\Admin\Resources\CouponResource\RelationManagers\ServicesRelationManager;
use App\Models\Coupon;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coupon-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-coupon-fill';

    public static function getModelLabel(): string
    {
        return __('Coupon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('Code'))
                    ->required()
                    ->maxLength(255)
                    ->unique(static::getModel(), 'code', ignoreRecord: true)
                    ->placeholder(__('Enter the code of the coupon')),

                TextInput::make('value')
                    ->label(__('Value'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get) => $get('type') === 'percentage' ? 100 : null)
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->suffix(fn (Get $get) => $get('type') === 'percentage' ? '%' : config('settings.default_currency'))
                    ->placeholder(__('Enter the value of the coupon')),

                Select::make('type')
                    ->label(__('Type'))
                    ->required()
                    ->default('percentage')
                    ->live()
                    ->options([
                        'percentage' => __('Percentage'),
                        'fixed' => __('Fixed amount'),
                    ])
                    ->placeholder(__('Select the type of the coupon')),
                Select::make('applies_to')
                    ->label(__('Applies To'))
                    ->required()
                    ->default('all')
                    ->options([
                        'all' => __('Price and Setup Fee'),
                        'price' => __('Price only'),
                        'setup_fee' => __('Setup Fee only'),
                    ]),

                TextInput::make('recurring')
                    ->label(__('Recurring'))
                    ->numeric()
                    ->nullable()
                    ->minValue(0)
                    ->hidden(fn (Get $get) => $get('applies_to') === 'free_setup')
                    ->placeholder(__('How many billing cycles the discount will be applied'))
                    ->helperText(__('Enter 0 to apply it to all billing cycles, 1 (or leave empty) to apply it only to the first billing cycle, etc.')),

                TextInput::make('max_uses')
                    ->label(__('Max Uses'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('Enter the maximum number of total uses of the coupon')),

                TextInput::make('max_uses_per_user')
                    ->label(__('Max Uses Per User'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('Enter the maximum number of uses per user')),

                DatePicker::make('starts_at')
                    ->label(__('Starts At')),

                DatePicker::make('expires_at')
                    ->label(__('Expires At')),

                Select::make('products')
                    ->label(__('Products'))
                    ->relationship(
                        name: 'products',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->with('category')
                    )
                    ->getOptionLabelFromRecordUsing(fn (Product $record) => "{$record->name} - {$record->category->name} (#{$record->id})")
                    ->multiple()
                    ->preload()
                    ->placeholder(__('Select the products that this coupon applies to'))
                    ->hint(__('Leave empty to apply the coupon to all products')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('value')->searchable()->formatStateUsing(fn ($record) => $record->value . ($record->type === 'percentage' ? '%' : config('settings.default_currency'))),
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
            ServicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
