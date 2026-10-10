<?php

namespace App\Admin\Resources;

use App\Admin\Components\UserComponent;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Admin\Resources\OrderResource\RelationManagers\ServiceRelationManager;
use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?int $navigationSort = 50;

    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shopping-bag-4-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-shopping-bag-4-fill';

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Business management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Order management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserComponent::make('user_id')
                    ->disabled(fn (?Order $record) => $record?->services()->exists())
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // update all the services user_id
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['user_id' => $get('user_id')]))->toArray());
                    }),
                Select::make('currency_code')
                    ->label(__('Currency'))
                    ->required()
                    ->disabled(fn (?Order $record) => $record?->services()->exists())
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // update all the services currency_code
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['currency_code' => $get('currency_code')]))->toArray());
                    })
                    ->options(Currency::query()->pluck('code', 'code'))
                    ->helperText(__('Does not convert the price, only displays the currency symbol'))
                    ->placeholder(__('Select the currency')),
                Repeater::make('services')
                    ->relationship('services')
                    ->label(__('Services'))
                    ->disabled(fn (?Order $record) => $record?->services()->exists())
                    ->helperText(fn (?Order $record) => $record?->services()->exists() ? __('Orders with created services can only be changed from the related service and invoice.') : null)
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Hidden::make('user_id')
                            ->default(fn (Get $get) => $get('../../user_id')),
                        Hidden::make('currency_code')
                            ->default(fn (Get $get) => $get('../../currency_code')),
                        Select::make('product_id')
                            ->label(__('Product'))
                            ->required()
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->with('category')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Product $product) => "{$product->name} - {$product->category->name} (#{$product->id})")
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('plan_id', null);
                                $set('configs', []);
                                $set('custom_configs', []);
                            })
                            ->placeholder(__('Select the product')),
                        Select::make('plan_id')
                            ->label(__('Plan'))
                            ->required()
                            ->rules(fn (Get $get) => ['required', function ($attribute, $value, $fail) use ($get) {
                                $validPlan = Plan::query()
                                    ->where('priceable_type', Product::class)
                                    ->where('priceable_id', $get('product_id'))
                                    ->whereKey($value)
                                    ->where(function (Builder $query) use ($get) {
                                        $query->where('type', 'free')
                                            ->orWhereHas('prices', fn (Builder $query) => $query->where('currency_code', $get('../../currency_code')));
                                    })
                                    ->exists();

                                if (!$validPlan) {
                                    $fail(__('The selected plan is invalid.'));
                                }
                            }])
                            ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_type', Product::class)->where('priceable_id', $get('product_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->disabled(fn (Get $get) => (!$get('product_id') || !$get('../../currency_code')))
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                if (!$get('product_id') || !$get('plan_id') || !$get('../../currency_code')) {
                                    return;
                                }
                                self::updateServicePrice($get, $set);
                            })
                            ->placeholder(__('Select the plan')),
                        Repeater::make('configs')
                            ->label(__('Config Options'))
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Select::make('config_option_id')
                                    ->label(__('Config Option'))
                                    ->options(fn (Get $get) => Product::find($get('../../product_id'))?->configOptions()
                                        ->whereIn('type', ['select', 'radio', 'slider', 'checkbox'])
                                        ->pluck('name', 'config_options.id') ?? [])
                                    ->rules(fn (Get $get) => ['required', function ($attribute, $value, $fail) use ($get) {
                                        if (!Product::find($get('../../product_id'))?->configOptions()->whereIn('type', ['select', 'radio', 'slider', 'checkbox'])->whereKey($value)->exists()) {
                                            $fail(__('The selected config option is invalid.'));
                                        }
                                    }])
                                    ->required()
                                    ->live()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->afterStateUpdated(function (Set $set, Get $get) {
                                        $set('config_value_id', null);
                                        if ($get('../../plan_id') && $get('../../currency_code')) {
                                            self::updateServicePrice($get, $set, true);
                                        }
                                    })
                                    ->searchable()
                                    ->preload(),
                                Select::make('config_value_id')
                                    ->label(__('Config Value'))
                                    ->options(fn (Get $get) => ConfigOption::find($get('config_option_id'))?->children()->pluck('name', 'id') ?? [])
                                    ->rules(fn (Get $get) => ['required', function ($attribute, $value, $fail) use ($get) {
                                        if (!ConfigOption::whereKey($value)->where('parent_id', $get('config_option_id'))->exists()) {
                                            $fail(__('The selected config value is invalid.'));
                                        }
                                    }])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get) {
                                        if ($get('../../plan_id') && $get('../../currency_code')) {
                                            self::updateServicePrice($get, $set, true);
                                        }
                                    })
                                    ->disabled(fn (Get $get) => !$get('config_option_id'))
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->visible(fn (Get $get) => (bool) Product::find($get('product_id'))?->configOptions()->whereIn('type', ['select', 'radio', 'slider', 'checkbox'])->exists()),
                        Repeater::make('custom_configs')
                            ->label(__('Custom Configurations'))
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Select::make('option_id')
                                    ->label(__('Config Option'))
                                    ->options(fn (Get $get) => Product::find($get('../../product_id'))?->configOptions()
                                        ->whereIn('type', ['text', 'number'])
                                        ->pluck('name', 'config_options.id') ?? [])
                                    ->rules(fn (Get $get) => ['required', function ($attribute, $value, $fail) use ($get) {
                                        if (!Product::find($get('../../product_id'))?->configOptions()->whereIn('type', ['text', 'number'])->whereKey($value)->exists()) {
                                            $fail(__('The selected config option is invalid.'));
                                        }
                                    }])
                                    ->required()
                                    ->live()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('value')
                                    ->label(__('Value'))
                                    ->type(fn (Get $get) => ConfigOption::find($get('option_id'))?->type === 'number' ? 'number' : 'text')
                                    ->rules(fn (Get $get) => ConfigOption::find($get('option_id'))?->type === 'number'
                                        ? ['required', 'numeric']
                                        : ['required', 'string'])
                                    ->required()
                                    ->disabled(fn (Get $get) => !$get('option_id')),
                            ])
                            ->visible(fn (Get $get) => (bool) Product::find($get('product_id'))?->configOptions()->whereIn('type', ['text', 'number'])->exists()),
                        TextInput::make('quantity')
                            ->label(__('Quantity'))
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->placeholder(__('Enter the quantity')),
                        TextInput::make('price')
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn (Component $component, Get $get) => $component->getRecord()?->currency->suffix ?? Currency::where('code', $get('../../currency_code'))->first()?->suffix)
                            ->prefix(fn (Component $component, Get $get) => $component->getRecord()?->currency->prefix ?? Currency::where('code', $get('../../currency_code'))->first()?->prefix)
                            ->label(__('Price'))
                            ->required()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->placeholder(__('Enter the price')),
                        TextInput::make('subscription_id')
                            ->label(__('Subscription ID'))
                            ->nullable()
                            ->placeholder(__('Enter the subscription ID'))
                            ->hintActions([
                                Action::make('Cancel Subscription ID')
                                    ->action(function (Component $component) {
                                        if (ExtensionHelper::cancelSubscription($component->getRecord())) {
                                            Notification::make('Subscription Cancelled')
                                                ->title(__('The subscription has been successfully cancelled'))
                                                ->success()
                                                ->send();
                                        } else {
                                            Notification::make('Subscription Not Cancelled')
                                                ->title(__('The subscription could not be cancelled'))
                                                ->error()
                                                ->send();
                                        }
                                        // Update the record to remove the subscription ID
                                        $component->getRecord()->update(['subscription_id' => null]);
                                    })
                                    ->requiresConfirmation()
                                    ->label(__('Cancel Subscription'))
                                    ->hidden(fn (Component $component) => !$component->getRecord()?->subscription_id),
                            ]),
                    ])
                    ->afterCreate(function (array $data, Service $record) {
                        foreach ($data['configs'] ?? [] as $config) {
                            $record->configs()->create([
                                'config_option_id' => $config['config_option_id'],
                                'config_value_id' => $config['config_value_id'],
                            ]);
                        }
                        $options = $record->product->configOptions()->whereIn('type', ['text', 'number'])->get()->keyBy('id');
                        foreach ($data['custom_configs'] ?? [] as $config) {
                            $option = $options->get($config['option_id']);
                            if ($option) {
                                $record->properties()->updateOrCreate([
                                    'key' => $option->env_variable ?: $option->name,
                                ], [
                                    'name' => $option->name,
                                    'value' => $config['value'],
                                ]);
                            }
                        }
                    }),
            ]);
    }

    private static function updateServicePrice(Get $get, Set $set, bool $insideConfig = false): void
    {
        $path = $insideConfig ? '../../' : '';
        $plan = Plan::find($get($path . 'plan_id'));
        if (!$plan) {
            return;
        }

        $currency = $get($path . 'currency_code');
        $price = (float) ($plan->price($currency)->price ?? 0);
        foreach ($get($insideConfig ? '../' : 'configs') ?? [] as $config) {
            $value = ConfigOption::find($config['config_value_id'] ?? null);
            if ($value) {
                $price += (float) ($value->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit, currency: $currency)->price ?? 0);
            }
        }

        $set($path . 'price', number_format($price, 2, '.', ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('services.product'))
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->description(fn (Order $record) => $record->user->email)
                    ->searchable(query: fn (Builder $query, $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"))),
                TextColumn::make('services.product.name')
                    ->label(__('Product'))
                    ->listWithLineBreaks(),
                TextColumn::make('currency.code')
                    ->label(__('Currency'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label(__('Total')),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                SelectFilter::make('product')
                    ->label(__('Product'))
                    ->relationship('services.product', 'name')
                    ->searchable(),
                SelectFilter::make('service_status')
                    ->label(__('Service status'))
                    ->options([
                        'active' => __('Active'),
                        'pending' => __('Pending'),
                        'suspended' => __('Suspended'),
                        'cancelled' => __('Cancelled'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, $status): Builder => $query->whereHas('services', fn (Builder $query) => $query->where('status', $status)))),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '>=', $date . ' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->where('created_at', '<=', $date . ' 23:59:59'))),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete')->before(function (DeleteBulkAction $action, $records) {
                        foreach ($records as $record) {
                            if ($record->services()->exists()) {
                                Notification::make()
                                    ->title(__('Whoops!'))
                                    ->body(__('You cannot delete this order while it has services. Cancel or terminate the services first.'))
                                    ->danger()
                                    ->send();
                                $action->cancel();

                                return;
                            }
                        }
                    }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ServiceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
