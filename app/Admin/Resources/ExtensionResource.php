<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Extensions;
use App\Admin\Resources\ExtensionResource\Pages\EditExtension;
use App\Admin\Resources\ExtensionResource\Pages\ListExtensions;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExtensionResource extends Resource
{
    protected static ?string $model = Extension::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-puzzle-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-puzzle-fill';

    protected static ?string $cluster = Extensions::class;

    public static function getModelLabel(): string
    {
        return __('Extension');
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
        return $schema
            ->components([
                Toggle::make('enabled'),
                Section::make(__('Extension Settings'))
                    ->columnSpanFull()
                    ->description(__('Specific settings for the selected extension'))
                    ->schema([
                        Grid::make()->schema(fn (Get $get) => ExtensionHelper::getConfigAsInputs('other', $get('extension'), $get('settings')))->key('settings'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->formatStateUsing(fn ($state) => __($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state) => __(ucfirst(str_replace('_', ' ', $state))))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->headerActions([
                Action::make('create')
                    ->label(__('Install Extension'))
                    ->url(fn () => \App\Admin\Pages\Extension::getUrl(['tab' => 'installable'])),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotIn('type', ['gateway', 'server']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtensions::route('/'),
            'edit' => EditExtension::route('/{record}/edit'),
        ];
    }
}
