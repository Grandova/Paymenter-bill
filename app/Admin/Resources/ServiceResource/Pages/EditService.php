<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Enums\InvoiceTransactionStatus;
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
use Illuminate\Support\Facades\DB;

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
                ->action(function (array $data, Service $record, DeleteAction $action): void {
                    if ($record->invoices()->where('status', 'pending')->whereHas('transactions', function ($query) {
                        $query->whereIn('status', [
                            InvoiceTransactionStatus::Processing->value,
                            InvoiceTransactionStatus::Succeeded->value,
                        ]);
                    })->exists()) {
                        Notification::make('Error')
                            ->title(__('The service cannot be deleted while an invoice payment is in progress.'))
                            ->danger()
                            ->send();
                        $action->halt();
                    }

                    try {
                        if (($data['deleteExtensionServer'] ?? false)) {
                            if (ExtensionHelper::terminateServer($record) === false) {
                                throw new Exception(__('Failed to terminate server'));
                            }
                        }
                    } catch (Exception $e) {
                        report($e);

                        Notification::make('Error')
                            ->title(__('Error occured while deleting the related server:'))
                            ->body(__($e->getMessage()))
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                    DB::transaction(function () use ($record, $data) {
                        $service = Service::query()->lockForUpdate()->findOrFail($record->id);
                        $service->removePendingRenewalInvoiceItems(__('Service deleted by administrator'));

                        if ($service->status !== Service::STATUS_CANCELLED
                            && (!$service->product->server_id || $service->status === Service::STATUS_PENDING || ($data['deleteExtensionServer'] ?? false))
                            && $service->product->stock !== null) {
                            $service->product->increment('stock', $service->quantity);
                        }

                        $service->delete();
                    });
                }),
            Action::make('changeStatus')
                ->label(__('Instance operations'))
                ->authorize(fn (Service $record) => ServiceResource::canEdit($record))
                ->visible(fn (Service $record) => $record->product->server !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (Service $record) => __('The operation will be sent to the automation interface for service #:id. Termination permanently deletes the remote instance.', ['id' => $record->id]))
                ->schema([
                    Select::make('action')
                        ->label(__('Action'))
                        ->options(function (Service $record) {
                            $options = [
                                'create' => __('Create server'),
                                'suspend' => __('Suspend server'),
                                'unsuspend' => __('Unsuspend server'),
                                'terminate' => __('Terminate server'),
                                'upgrade' => __('Upgrade server'),
                            ];

                            return array_filter($options, fn ($action) => $record->product->server
                                && ExtensionHelper::hasFunction($record->product->server, $action . 'Server')
                                && ($action !== 'terminate' || ServiceResource::canDelete($record)), ARRAY_FILTER_USE_KEY);
                        })->required(),
                    Checkbox::make('sendNotification')
                        ->label(__('Send Notification'))
                        ->default(false),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    try {
                        switch ($data['action']) {
                            case 'create':
                                $sdata = ExtensionHelper::createServer($record);
                                if ($sdata === false) {
                                    throw new Exception(__('Failed to create server'));
                                }
                                if ($data['sendNotification']) {
                                    NotificationHelper::serverCreatedNotification($record->user, $record, $sdata);
                                }
                                break;
                            case 'suspend':
                                $sdata = ExtensionHelper::suspendServer($record);
                                if ($sdata === false) {
                                    throw new Exception(__('Failed to suspend server'));
                                }
                                $record->update(['status' => Service::STATUS_SUSPENDED]);
                                break;
                            case 'unsuspend':
                                $sdata = ExtensionHelper::unsuspendServer($record);
                                if ($sdata === false) {
                                    throw new Exception(__('Failed to unsuspend server'));
                                }
                                $record->update(['status' => Service::STATUS_ACTIVE]);
                                break;
                            case 'terminate':
                                if ($record->invoices()->where('status', 'pending')->whereHas('transactions', function ($query) {
                                    $query->whereIn('status', [
                                        InvoiceTransactionStatus::Processing->value,
                                        InvoiceTransactionStatus::Succeeded->value,
                                    ]);
                                })->exists()) {
                                    throw new Exception(__('The service cannot be terminated while an invoice payment is in progress.'));
                                }

                                $sdata = ExtensionHelper::terminateServer($record);
                                if ($sdata === false) {
                                    throw new Exception(__('Failed to terminate server'));
                                }
                                DB::transaction(function () use ($record) {
                                    $service = Service::query()->lockForUpdate()->findOrFail($record->id);
                                    if ($service->status === Service::STATUS_CANCELLED) {
                                        return;
                                    }

                                    $service->update(['status' => Service::STATUS_CANCELLED]);
                                    $service->removePendingRenewalInvoiceItems(__('Service terminated by administrator'));

                                    if ($service->product->stock !== null) {
                                        $service->product->increment('stock', $service->quantity);
                                    }
                                });
                                break;
                            case 'upgrade':
                                $sdata = ExtensionHelper::upgradeServer($record);
                                if ($sdata === false) {
                                    throw new Exception(__('Failed to upgrade server'));
                                }
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
