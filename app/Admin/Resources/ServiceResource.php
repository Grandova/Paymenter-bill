<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Components\UserComponent;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\Resources\ServiceResource\RelationManagers\ConfigOptionsRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\InvoicesRelationManager;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    public static function getModelLabel(): string
    {
        return __('Service');
    }

    public static function getNavigationBadge(): ?string
    {
        return Service::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static ?string $cluster = Services::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->live()
                    ->preload()
                    ->afterStateUpdated(fn (Set $set) => $set('plan_id', null))
                    ->placeholder(__('Select the product')),
                Select::make('plan_id')
                    ->label(__('Plan'))
                    ->required()
                    ->rules(fn (Get $get) => ['required', function ($attribute, $value, $fail) use ($get) {
                        $validPlan = Plan::query()
                            ->where('priceable_id', $get('product_id'))
                            ->where('priceable_type', Product::class)
                            ->whereKey($value)
                            ->where(function (Builder $query) use ($get) {
                                $query->where('type', 'free')
                                    ->orWhereHas('prices', fn (Builder $query) => $query->where('currency_code', $get('currency_code')));
                            })
                            ->exists();

                        if (!$validPlan) {
                            $fail(__('The selected plan is invalid.'));
                        }
                    }])
                    ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_id', $get('product_id'))->where('priceable_type', Product::class))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => !$get('product_id'))
                    ->placeholder(__('Select the plan')),
                UserComponent::make('user_id'),
                Select::make('status')
                    ->label(__('Status'))
                    ->required()
                    ->disabled(fn (?Service $record) => $record?->product->server_id !== null)
                    ->options([
                        // active, pending, suspended, cancelled
                        'active' => __('Active'),
                        'pending' => __('Pending'),
                        'suspended' => __('Suspended'),
                        'cancelled' => __('Cancelled'),
                    ])
                    ->default('pending'),
                TextInput::make('quantity')
                    ->label(__('Quantity'))
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->placeholder(__('Enter the quantity')),
                DatePicker::make('expires_at')
                    ->label(__('Expires At'))
                    ->required(fn (Get $get) => $get('plan')?->type != 'one-time' && $get('plan')?->type != 'free' && $get('status') !== 'pending')
                    ->placeholder(__('Select the expiration date')),
                DatePicker::make('suspend_hold_until')
                    ->label(__('Do Not Suspend Until'))
                    ->helperText(__('While set to a future date, the automatic suspension cronjob will skip this service, even if it is overdue. This does not affect manual suspension or termination.'))
                    ->minDate(now())
                    ->nullable()
                    ->placeholder(__('Select a date to hold off suspension until')),
                Select::make('coupon_id')
                    ->label(__('Coupon'))
                    ->relationship('coupon', 'code')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Select the coupon')),
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
                    ->hintAction(
                        Action::make('Recalculate Price')
                            ->action(function (Component $component, Service $service) {
                                if ($service) {
                                    Notification::make('Price Recalculated')
                                        ->title(__('The price has been successfully recalculated'))
                                        ->success()
                                        ->send();
                                    // Update the form field
                                    $component->state($service->calculatePrice());
                                }
                            })
                            ->label(__('Recalculate Price'))
                            ->icon('ri-refresh-line')
                    ),
                Select::make('billing_agreement_id')
                    ->label(__('Billing Agreement'))
                    ->relationship('billingAgreement', 'name', fn (Builder $query, Get $get) => $query->where('user_id', $get('user_id')))
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Select the billing agreement')),
                Toggle::make('auto_renew')
                    ->label(__('Balance Auto-Renew'))
                    ->helperText(__('Balance auto-renew is opt-in per service.')),
                TextInput::make('subscription_id')
                    ->label(__('Subscription ID (deprecated)'))
                    ->nullable()
                    ->placeholder(__('Enter the subscription ID'))
                    ->hintAction(
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
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->searchable(true, fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('product.name')
                    ->label(__('Product'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Service $record) => match ($record->status) {
                        'pending' => 'gray',
                        'active' => 'success',
                        'cancelled' => 'danger',
                        'suspended' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => __(ucfirst($state)))
                    ->label(__('Status'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('auto_renew')
                    ->label(__('Balance Auto-Renew'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? __('Enabled') : __('Disabled'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('expires_at')
                    ->label(__('Expires At'))
                    ->date()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'pending' => __('Pending'),
                        'suspended' => __('Suspended'),
                        'cancelled' => __('Cancelled'),
                    ]),
                SelectFilter::make('auto_renew')
                    ->label(__('Balance Auto-Renew'))
                    ->options([1 => __('Enabled'), 0 => __('Disabled')]),
                SelectFilter::make('user')
                    ->label(__('User'))
                    ->relationship('user', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name . ' (' . $record->email . ')')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->label(__('Product'))
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('expires_at')
                    ->form([
                        DatePicker::make('expires_from')->label(__('Expires From')),
                        DatePicker::make('expires_until')->label(__('Expires Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['expires_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '>=', $date),
                            )
                            ->when(
                                $data['expires_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            PropertiesRelationManager::class,
            ConfigOptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListService::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
