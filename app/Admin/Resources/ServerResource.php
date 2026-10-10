<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ServerResource\Pages\CreateServer;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServerResource extends Resource
{
    protected static ?int $navigationSort = 30;

    protected static ?string $model = Server::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-server-fill';

    public static function getModelLabel(): string
    {
        return __('Server');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Business management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Automation interfaces');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->name;
    }

    public static function form(Schema $schema): Schema
    {
        $servers = ExtensionHelper::getExtensions('server');

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Interface name'))
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        static::getModel(),
                        'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule) => $rule->where('deleted_at', null)
                    )
                    ->placeholder(__('Enter the name of the server')),
                Select::make('extension')
                    ->label(__('Interface plugin'))
                    ->required()
                    ->searchable()
                    ->options(array_combine(
                        array_column($servers, 'name'),
                        array_column($servers, 'name')
                    ))
                    ->live(onBlur: true)
                    ->disabledOn('edit')
                    ->afterStateUpdated(fn (Select $component) => $component
                        ->getContainer()
                        ->getComponent('settings')
                        ->getChildSchema()
                        ->fill())
                    ->placeholder(__('Select the type of the server'))
                    ->hintAction(
                        Action::make('Test Configuration')
                            ->action(function (Get $get) {
                                try {
                                    $extension = ExtensionHelper::getExtension('server', $get('extension'), $get('settings') ?? []);
                                    $connection = $extension->testConfig();

                                    if ($connection === true) {
                                        Notification::make()
                                            ->title(__('Configuration is correct'))
                                            ->success()->send();
                                    } else {
                                        Notification::make()
                                            ->title(__('Connection failed'))
                                            ->body(is_string($connection) ? $connection : __('Please check the server address, API key, and network connection.'))
                                            ->danger()->send();
                                    }
                                } catch (\Throwable $e) {
                                    report($e);
                                    Notification::make()
                                        ->title(__('Connection failed'))
                                        ->body(__('Please check the server address, API key, and network connection. The details were saved to the system log.'))
                                        ->danger()->send();
                                }
                            })
                            ->label(__('Test Connection'))
                            ->hidden(function (Get $get) {
                                if (!$get('extension')) {
                                    return true;
                                }

                                return !method_exists(ExtensionHelper::getExtension('server', $get('extension')), 'testConfig');
                            })
                    ),
                Section::make(__('Connection parameters'))
                    ->columnSpanFull()
                    ->description(__('Specific settings for the selected server'))
                    ->schema([
                        Grid::make()->schema(fn (Get $get) => ExtensionHelper::getConfigAsInputs('server', $get('extension'), $get('settings')))->key('settings'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Interface name'))->searchable()->sortable(),
                TextColumn::make('extension')->label(__('Interface plugin'))->searchable(),
                TextColumn::make('products_count')
                    ->label(__('Bound products'))
                    ->url(fn (Server $record) => ProductResource::canViewAny()
                        ? ProductResource::getUrl('index', ['tableFilters' => ['server_id' => ['value' => $record->id]]])
                        : null)
                    ->numeric()
                    ->sortable(),
                TextColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('Yes') : __('No'))
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('extension')
                    ->label(__('Interface plugin'))
                    ->options(fn () => Server::query()->distinct()->orderBy('extension')->pluck('extension', 'extension')),
                SelectFilter::make('enabled')
                    ->label(__('Enabled'))
                    ->options([1 => __('Yes'), 0 => __('No')]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('testConnection')
                    ->label(__('Test Connection'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Server $record) {
                        try {
                            $connection = ExtensionHelper::testConfig($record, $record->settings->pluck('value', 'key')->toArray());

                            if ($connection === true) {
                                Notification::make()
                                    ->title(__('Configuration is correct'))
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(__('Connection failed'))
                                    ->body(is_string($connection) ? $connection : __('Please check the server address, API key, and network connection.'))
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title(__('Connection failed'))
                                ->body(__('Please check the server address, API key, and network connection. The details were saved to the system log.'))
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Server $record) => ExtensionHelper::hasFunction($record, 'testConfig')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->before(function (DeleteBulkAction $action, $records) {
                        foreach ($records as $record) {
                            if (Product::where('server_id', $record->id)->exists()) {
                                Notification::make()
                                    ->title(__('Whoops!'))
                                    ->body(__('You cannot delete this server because it is assigned to one or more products. Reassign those products first.'))
                                    ->danger()
                                    ->send();
                                $action->cancel();

                                return;
                            }
                        }

                        foreach ($records as $record) {
                            ExtensionHelper::call($record, 'disabled', [$record], mayFail: true);
                        }
                    }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('products');
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
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'edit' => EditServer::route('/{record}/edit'),
        ];
    }
}
