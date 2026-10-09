<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class Support extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Support'))
            ->emptyStateHeading(__('No open tickets'))
            ->query(
                Ticket::query()
                    ->where('status', '!=', 'closed')
                    ->with('user')
                    ->withMax('messages', 'created_at')
                    ->orderByDesc('messages_max_created_at')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('subject')
                    ->label(__('Subject')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (Ticket $record) => match ($record->status) {
                        'open' => 'success',
                        'closed' => 'danger',
                        'replied' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => __(ucfirst($state))),
                TextColumn::make('user.name')
                    ->label(__('User')),
                TextColumn::make('created_at')
                    ->label(__('Created At')),
            ])
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('edit', ['record' => $record]))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.support');
    }
}
