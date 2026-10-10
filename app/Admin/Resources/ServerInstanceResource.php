<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ServerInstanceResource\Pages\ListServerInstances;
use App\Admin\Resources\ServerInstanceResource\Pages\ManageServerInstance;
use App\Models\Server;
use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServerInstanceResource extends ServiceResource
{
    protected static ?int $navigationSort = 40;

    protected static ?string $cluster = null;

    protected static ?string $slug = 'all-servers';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-server-fill';

    public static function getModelLabel(): string
    {
        return __('Server instance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('All servers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Business management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Instance management');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getEloquentQuery()->where('status', 'pending')->count() ?: null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('product.server')->with(['product.server', 'properties', 'user', 'plan', 'currency']);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->columns([
                TextColumn::make('id')->label(__('ID'))->searchable()->sortable(),
                TextColumn::make('label')
                    ->label(__('Name'))
                    ->description(fn (Service $record) => $record->product->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('label', 'like', "%$search%")
                        ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', "%$search%"))),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query
                        ->where('first_name', 'like', "%$search%")
                        ->orWhere('last_name', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%"))),
                TextColumn::make('product.server.name')->label(__('Assigned server'))->searchable()->sortable(),
                TextColumn::make('product.server.extension')->label(__('Platform'))->badge(),
                TextColumn::make('status')
                    ->label(__('Service status'))
                    ->badge()
                    ->color(fn (Service $record) => match ($record->status) {
                        'pending' => 'gray',
                        'active' => 'success',
                        'cancelled' => 'danger',
                        'suspended' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => __(ucfirst($state)))
                    ->sortable(),
                TextColumn::make('plan.name')->label(__('Plan'))->toggleable(),
                TextColumn::make('expires_at')->label(__('Expires At'))->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('created_at')->label(__('Created At'))->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->pushFilters([
                SelectFilter::make('server')
                    ->label(__('Assigned server'))
                    ->options(fn () => Server::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $query, $value) => $query
                        ->whereHas('product', fn (Builder $query) => $query->where('server_id', $value)))),
            ])
            ->recordUrl(fn (Service $record) => $record->product->server->extension === 'Clicd' ? static::getUrl('view', ['record' => $record]) : ServiceResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                EditAction::make()->label(__('Manage'))->url(fn (Service $record) => $record->product->server->extension === 'Clicd' ? static::getUrl('view', ['record' => $record]) : ServiceResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServerInstances::route('/'),
            'view' => ManageServerInstance::route('/{record}'),
        ];
    }
}
