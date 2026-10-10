<?php

namespace Paymenter\Extensions\Others\Announcements\Admin\Resources;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Announcements\Admin\Resources\AnnouncementResource\Pages\CreateAnnouncement;
use Paymenter\Extensions\Others\Announcements\Admin\Resources\AnnouncementResource\Pages\EditAnnouncement;
use Paymenter\Extensions\Others\Announcements\Admin\Resources\AnnouncementResource\Pages\ListAnnouncements;
use Paymenter\Extensions\Others\Announcements\Models\Announcement;

class AnnouncementResource extends Resource
{
    protected static ?int $navigationSort = 35;

    protected static ?string $model = Announcement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-megaphone-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-megaphone-fill';

    public static function getModelLabel(): string
    {
        return __('Announcement');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if (($get('slug') ?? '') !== Str::slug($old)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    })
                    ->placeholder(__('Enter the title of the announcement')),
                TextInput::make('slug')
                    ->label(__('Slug'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('Enter the slug of the announcement')),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('Short description to show on the announcement list')),
                DateTimePicker::make('published_at')
                    ->label(__('Published At'))
                    ->default(now())
                    ->helperText(__('Publication time uses :timezone. Future dates are scheduled.', ['timezone' => config('app.timezone')]))
                    ->required()
                    ->placeholder(__('Enter the date and time when the announcement should be published')),
                Toggle::make('is_active')
                    ->label(__('Enable publication'))
                    ->default(false),
                RichEditor::make('content')
                    ->columnSpanFull()
                    ->label(__('Content'))
                    ->required()
                    ->placeholder(__('Enter the content of the announcement')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('published_at')
                    ->searchable()
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (Announcement $record) => !$record->is_active || !$record->published_at ? __('Draft') : ($record->published_at->isFuture() ? __('Scheduled') : __('Published')))
                    ->badge()
                    ->color(fn (Announcement $record) => !$record->is_active || !$record->published_at ? 'gray' : ($record->published_at->isFuture() ? 'warning' : 'success'))
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

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
