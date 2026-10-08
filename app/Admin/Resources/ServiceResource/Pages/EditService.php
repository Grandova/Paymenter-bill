<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->form(function (DeleteAction $action) {
                    $status = !in_array($this->record->status, [Service::STATUS_PENDING, Service::STATUS_CANCELLED]) && $this->record->product->server_id !== null;
                    if (!$status) {
                        return [];
                    }

                    return [
                        Checkbox::make('deleteExtensionServer')
                            ->label(__('Also trigger deletion of server'))
                            ->default(true),
                    ];
                })
                ->action(function (array $data, Service $record): void {
                    try {
                        if (($data['deleteExtensionServer'] ?? false)) {
                            ExtensionHelper::terminateServer($record);
                        }
                    } catch (Exception $e) {
                        report($e);

                        Notification::make('Error')
                            ->title(__('Error occured while deleting the related server:'))
                            ->body(__($e->getMessage()))
                            ->danger()
                            ->send();
                    }
                    $record->delete();
                }),
            Action::make('changeStatus')
                ->label(__('Trigger Extension Action'))
                ->schema([
                    Select::make('action')
                        ->label(__('Action'))
                        ->options([
                            'create' => __('Create server'),
                            'suspend' => __('Suspend server'),
                            'unsuspend' => __('Unsuspend server'),
                            'terminate' => __('Terminate server'),
                            'upgrade' => __('Upgrade server'),
                        ])->required(),
                    Checkbox::make('sendNotification')
                        ->label(__('Send Notification'))
                        ->default(false),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    try {
                        switch ($data['action']) {
                            case 'create':
                                $sdata = ExtensionHelper::createServer($record);
                                if ($data['sendNotification']) {
                                    NotificationHelper::serverCreatedNotification($record->order->user, $record, $sdata);
                                }
                                break;
                            case 'suspend':
                                $sdata = ExtensionHelper::suspendServer($record);
                                break;
                            case 'unsuspend':
                                $sdata = ExtensionHelper::unsuspendServer($record);
                                break;
                            case 'terminate':
                                $sdata = ExtensionHelper::terminateServer($record);
                                break;
                            case 'upgrade':
                                $sdata = ExtensionHelper::upgradeServer($record);
                                break;
                        }
                    } catch (Exception $e) {
                        if (config('app.debug')) {
                            throw $e;
                        }
                        report($e);
                        Notification::make('Error')
                            ->title(__('Error occured while triggering the action:'))
                            ->body(__($e->getMessage()))
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                    Notification::make('Success')
                        ->title(__('Action triggered successfully'))
                        ->body(__('The action has been triggered successfully'))
                        ->success()
                        ->send();
                })
                ->color('primary')
                ->modalSubmitActionLabel(__('Trigger')),

            AuditAction::make()->auditChildren([
                'order',
                'invoices',
                'properties',
                'configs',
                'invoiceItems',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (!$this->record->cancellation()->exists()) {
            return [];
        }

        return [
            ServiceResource\Widgets\CancellationOverview::class,
        ];
    }
}
