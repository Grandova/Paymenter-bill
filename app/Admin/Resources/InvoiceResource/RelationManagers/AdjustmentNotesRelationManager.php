<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Enums\AdjustmentNoteStatus;
use App\Models\Invoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdjustmentNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'adjustmentNotes';

    protected function canModifyAdjustmentNotes(): bool
    {
        return !config('settings.immutable_invoices_enabled') || $this->getOwnerRecord()?->status === Invoice::STATUS_PENDING;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label(__('Number'))
                    ->helperText(__('The number will be generated automatically'))
                    ->disabled(),
                Hidden::make('type')
                    ->default('credit'),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->required()
                    ->numeric()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (is_numeric($state)) {
                            $set('type', $state < 0 ? 'credit' : 'debit');
                        }
                    })
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->placeholder(__('Enter the amount (negative = credit, positive = debit)')),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->placeholder(__('Enter a description')),
                Select::make('status')
                    ->label(__('Status'))
                    ->options([
                        AdjustmentNoteStatus::Active->value => __('Active'),
                        AdjustmentNoteStatus::Voided->value => __('Voided'),
                    ])
                    ->default(AdjustmentNoteStatus::Active->value)
                    ->required(),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Adjustment Notes');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Adjustment Note'))
            ->pluralModelLabel(__('Adjustment Notes'))
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                    })
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),
                TextColumn::make('formattedAmount')
                    ->label(__('Amount'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof AdjustmentNoteStatus ? $state->value : $state) {
                        AdjustmentNoteStatus::Active->value => 'success',
                        AdjustmentNoteStatus::Voided->value => 'danger',
                    })
                    ->formatStateUsing(fn ($state): string => __($state instanceof AdjustmentNoteStatus ? ucfirst($state->value) : ucfirst($state)))
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canModifyAdjustmentNotes()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canModifyAdjustmentNotes()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canModifyAdjustmentNotes()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canModifyAdjustmentNotes()),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
