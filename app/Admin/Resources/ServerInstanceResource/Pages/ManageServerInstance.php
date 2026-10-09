<?php

namespace App\Admin\Resources\ServerInstanceResource\Pages;

use App\Admin\Resources\ServerInstanceResource;
use App\Admin\Resources\ServiceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ManageServerInstance extends ViewRecord
{
    protected static string $resource = ServerInstanceResource::class;

    protected string $view = 'admin.server-instance';

    public function getTitle(): string
    {
        return $this->record->label;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('billing')->label('服务与账单')->url(ServiceResource::getUrl('edit', ['record' => $this->record]))];
    }
}
