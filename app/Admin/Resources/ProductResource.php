<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ProductResource\Pages\CreateProduct;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Classes\FilamentInput;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class ProductResource extends Resource
{
    protected static ?int $navigationSort = 10;

    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-instance-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-instance-fill';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Product');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Business management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Product management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Tabs')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('General')->label(__('Product information'))
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                                        if (($get('slug') ?? '') !== Str::slug($old)) {
                                            return;
                                        }

                                        $set('slug', Str::slug($state));
                                    }),
                                Select::make('category_id')
                                    ->label(__('Product groups'))
                                    ->relationship('category', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm(fn (Schema $schema) => CategoryResource::form($schema))
                                    ->createOptionAction(fn (Action $action) => $action->authorize(fn () => CategoryResource::canCreate()))
                                    ->required(),
                                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                                TextInput::make('sort')
                                    ->label(__('Sort order'))
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(255)
                                    ->default(0)
                                    ->required(),
                                TextInput::make('stock')->integer()->minValue(0)->nullable(),
                                TextInput::make('per_user_limit')->integer()->minValue(0)->nullable(),
                                Select::make('allow_quantity')->options([
                                    'disabled' => __('No'),
                                    'separated' => __('Separated'),
                                    'combined' => __('Combined'),
                                ])->default('separated')
                                    ->required(),
                                Textarea::make('email_template')
                                    ->hint(__('This snippet will be used in the email template.'))
                                    ->nullable(),
                                Checkbox::make('hidden')
                                    ->label(__('Hide product'))
                                    ->hint(__('Hide the product from the client area.')),

                                RichEditor::make('description')->nullable()->columnSpanFull(),
                                FileUpload::make('image')
                                    ->label(__('Image'))
                                    ->nullable()
                                    ->visibility('public')
                                    ->imageEditor()
                                    ->image()
                                    ->disk('public')
                                    ->acceptedFileTypes(['image/*']),
                            ]),
                        Tab::make('Server')->label(__('Automation and resources'))
                            ->schema([
                                Select::make('server_id')
                                    ->relationship('server', 'name')
                                    ->label(__('Automation interface'))
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('Changing the server affects existing services tied to this product. Create a new product to keep existing services on their current server.'))
                                    ->hintAction(
                                        Action::make('refresh')
                                            ->label(__('Refresh'))
                                            ->action(fn () => Cache::set('product_config', null, 0))
                                            ->hidden(fn (Get $get) => $get('server_id') === null)
                                    )
                                    ->live()
                                    ->afterStateUpdated(fn (Select $component) => $component
                                        ->getContainer()
                                        ->getComponent('extension_settings', withHidden: true)
                                        ->getChildSchema()
                                        ->fill()),

                                Grid::make()
                                    ->hidden(fn (Get $get) => $get('server_id') === null)
                                    ->columns(2)
                                    ->key('extension_settings')
                                    ->schema(
                                        function (Get $get, Component $livewire) {
                                            $server = $get('server_id');
                                            if ($server == null) {
                                                return [];
                                            }
                                            $settings = [];

                                            try {
                                                foreach (ExtensionHelper::getProductConfigOnce(Server::findOrFail($server), $get('settings')) as $setting) {
                                                    // Easier to use dot notation for settings
                                                    $setting['name'] = 'settings.' . $setting['name'];
                                                    $settings[] = FilamentInput::convert($setting);
                                                }
                                            } catch (Exception $e) {
                                                $settings[] = TextEntry::make('error')->state(__($e->getMessage()));
                                            }

                                            return $settings;
                                        }
                                    ),

                            ]),

                        Tab::make('Pricing')->label(__('Billing cycle and price'))
                            ->schema([self::plan()]),

                        Tab::make('Sales options')->label(__('Purchase options'))
                            ->schema([
                                Select::make('configurableOptions')
                                    ->label(__('Config Options'))
                                    ->relationship('configurableOptions', 'name')
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->placeholder(__('Select the configuration options customers can choose')),
                            ]),

                        Tab::make('Upgrades')->label(__('Upgrade settings'))
                            ->schema([
                                Select::make('upgrades')
                                    ->label(__('Upgrades'))
                                    ->relationship('upgrades', 'name', ignoreRecord: true)
                                    ->multiple()
                                    ->preload()
                                    ->placeholder(__('Select the products that this product can upgrade to')),
                            ]),
                    ]),
            ])->columns(1);
    }

    public static function plan()
    {
        return Repeater::make('plan')
            ->addActionLabel(__('Add new plan'))
            ->relationship('plans')
            ->name('name')
            ->reorderable()
            ->cloneable()
            ->collapsible()
            ->collapsed()
            ->orderColumn()
            ->defaultItems(1)
            ->minItems(1)
            ->columns(2)
            ->deleteAction(function (Action $action) {
                $action->before(function (?Product $record, $state, Action $action, array $arguments) {
                    if (!$record) {
                        return;
                    }
                    $key = $arguments['item'];
                    if (!isset($state[$key]['id'])) {
                        return;
                    }
                    $plan = $record->plans()->find($state[$key]['id']);
                    if ($plan->services()->count() > 0) {
                        Notification::make()
                            ->title(__('Whoops!'))
                            ->body(__('You cannot delete this plan because it is being used by one or more services.'))
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                });
            })
            ->itemLabel(fn (array $state) => $state['name'])
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'free' => __('Free'),
                        'one-time' => __('One Time'),
                        'recurring' => __('Recurring'),
                    ])
                    ->required()
                    ->live(debounce: 300)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if ($state === 'free') {
                            $set('every', null);
                            $set('price', 0);
                        }
                    })
                    ->placeholder(__('Select the type of the price'))
                    ->default('recurring'),

                Select::make('billing_cycle')
                    ->label(__('Common billing cycle'))
                    ->options([
                        'month:1' => __('Monthly'),
                        'month:3' => __('Quarterly'),
                        'month:6' => __('Semi-annually'),
                        'year:1' => __('Annually'),
                        'custom' => __('Custom'),
                    ])
                    ->dehydrated(false)
                    ->live()
                    ->afterStateHydrated(function (Select $component, Get $get) {
                        $cycle = $get('billing_unit') . ':' . $get('billing_period');
                        $component->state(in_array($cycle, ['month:1', 'month:3', 'month:6', 'year:1'], true) ? $cycle : 'custom');
                    })
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if (!in_array($state, ['month:1', 'month:3', 'month:6', 'year:1'], true)) {
                            return;
                        }

                        [$unit, $period] = explode(':', $state);
                        $set('billing_unit', $unit);
                        $set('billing_period', (int) $period);
                    })
                    ->columnSpanFull()
                    ->hidden(fn (Get $get) => $get('type') !== 'recurring'),
                TextInput::make('billing_period')
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->label(__('Time Interval'))
                    ->default(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('billing_cycle', 'custom'))
                    ->hidden(fn (Get $get) => $get('type') !== 'recurring'),

                Select::make('billing_unit')
                    ->options([
                        'hour' => __('Hour'),
                        'day' => __('Day'),
                        'week' => __('Week'),
                        'month' => __('Month'),
                        'year' => __('Year'),
                    ])
                    ->label(__('Billing period'))
                    ->required()
                    ->default('month')
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('billing_cycle', 'custom'))
                    ->hidden(fn (Get $get) => $get('type') !== 'recurring'),
                Repeater::make('pricing')
                    ->hidden(fn (Get $get) => $get('type') === 'free')
                    ->columns(3)
                    ->addActionLabel(__('Add new price'))
                    ->reorderable(false)
                    ->relationship('prices')
                    ->columnSpanFull()
                    ->maxItems(Currency::count())
                    ->defaultItems(1)
                    ->itemLabel(fn (array $state) => $state['currency_code'])
                    ->schema([
                        Select::make('currency_code')
                            ->options(function (Get $get, ?string $state) {
                                $pricing = collect($get('../../pricing'))->pluck('currency_code');
                                if ($state !== null) {
                                    $pricing = $pricing->filter(function ($code) use ($state) {
                                        return $code !== $state;
                                    });
                                }
                                $pricing = $pricing->filter(function ($code) {
                                    return $code !== null;
                                });

                                return Currency::whereNotIn('code', $pricing)->pluck('code', 'code');
                            })
                            ->live()
                            ->default(config('settings.default_currency'))
                            ->required(),
                        TextInput::make('price')
                            ->required()
                            ->label(__('Price'))
                            // Suffix based on chosen currency
                            ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                            ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                            ->live(onBlur: true)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->numeric()
                            ->minValue(0)
                            ->hidden(fn (Get $get) => $get('type') === 'free'),
                        TextInput::make('setup_fee')
                            ->label(__('Setup fee'))
                            ->live(onBlur: true)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->numeric()
                            ->minValue(0)
                            ->hidden(fn (Get $get) => $get('type') === 'free'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->where('products.name', 'like', "%{$search}%");
                }),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('category.name')->searchable(),
                TextColumn::make('server.name')
                    ->label(__('Provisioning interface'))
                    ->placeholder(__('Not assigned'))
                    ->searchable(),
                TextColumn::make('stock')
                    ->label(__('Stock'))
                    ->placeholder(__('Unlimited'))
                    ->sortable(),
                TextColumn::make('hidden')
                    ->label(__('Sales status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? __('Hidden') : __('On sale'))
                    ->color(fn (bool $state) => $state ? 'gray' : 'success')
                    ->sortable(),
                TextColumn::make('sort')
                    ->label(__('Sort order'))
                    ->sortable(),
                TextColumn::make('per_user_limit')
                    ->label(__('Per-user purchase limit'))
                    ->placeholder(__('No limit'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('server_id')
                    ->label(__('Provisioning interface'))
                    ->relationship('server', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('hidden')
                    ->label(__('Sales status'))
                    ->options([
                        0 => __('On sale'),
                        1 => __('Hidden'),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label(__('Bulk publish'))
                        ->visible(fn () => auth()->user()->hasPermission('admin.products.update'))
                        ->authorizeIndividualRecords('update')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each(fn (Product $record) => $record->update(['hidden' => false]))),
                    BulkAction::make('hide')
                        ->label(__('Bulk hide'))
                        ->visible(fn () => auth()->user()->hasPermission('admin.products.update'))
                        ->authorizeIndividualRecords('update')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each(fn (Product $record) => $record->update(['hidden' => true]))),
                ]),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('sort', 'asc');
            })
            ->defaultGroup(Group::make('category.name')->label(__('Product groups')))
            ->reorderable('sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
