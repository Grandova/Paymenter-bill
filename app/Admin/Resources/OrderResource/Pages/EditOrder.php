<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(function ($record, DeleteAction $action) {
                if ($record->services()->exists()) {
                    Notification::make()
                        ->title(__('Whoops!'))
                        ->body(__('You cannot delete this order while it has services. Cancel or terminate the services first.'))
                        ->danger()
                        ->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
