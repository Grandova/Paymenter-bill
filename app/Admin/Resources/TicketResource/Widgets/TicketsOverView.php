<?php

namespace App\Admin\Resources\TicketResource\Widgets;

use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TicketsOverView extends BaseWidget
{
    protected ?string $pollingInterval = '20s';

    protected function getStats(): array
    {
        return [
            Stat::make(__('Open Tickets'), Ticket::where('status', 'open')->count())
                ->color('success'),
            Stat::make(__('Closed Tickets'), Ticket::where('status', 'closed')->count())
                ->color('danger'),
            Stat::make(__('Replied Tickets'), Ticket::where('status', 'replied')->count())
                ->color('gray'),
        ];
    }
}
