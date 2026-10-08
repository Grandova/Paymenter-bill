<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Models\ServiceConfig;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConfigOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'configs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Config Options');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('configValue.id')
                    ->label(__('Config Value'))
                    ->required()
                    ->relationship('configValue', 'id', fn (Builder $query, ServiceConfig $record) => $query->where('parent_id', $record->config_option_id))
                    ->searchable()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                    ->getSearchResultsUsing(fn (string $search, ServiceConfig $record): array => $record->configOption->children()->where('name', 'like', "%$search%")->limit(50)->pluck('name', 'id')->toArray())
                    ->live()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Config Option'))
            ->pluralModelLabel(__('Config Options'))
            ->recordTitleAttribute('configOption.name')
            ->columns([
                TextColumn::make('configOption.name'),
                TextColumn::make('configValue.name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
