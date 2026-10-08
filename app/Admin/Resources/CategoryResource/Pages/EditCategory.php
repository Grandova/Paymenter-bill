<?php

namespace App\Admin\Resources\CategoryResource\Pages;

use App\Admin\Resources\CategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(function (DeleteAction $action) {
                if ($this->record->products()->exists() || $this->record->children()->exists()) {
                    Notification::make()
                        ->title(__('Cannot delete category'))
                        ->body(__('The category has products or children categories.'))
                        ->duration(5000)
                        ->icon('ri-error-warning-line')
                        ->danger()
                        ->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
