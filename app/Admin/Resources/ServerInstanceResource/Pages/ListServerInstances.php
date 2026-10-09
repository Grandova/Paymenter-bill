<?php

namespace App\Admin\Resources\ServerInstanceResource\Pages;

use App\Admin\Resources\ServerInstanceResource;
use App\Admin\Resources\ServiceResource;
use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Clicd\Services\LifecycleService;

class ListServerInstances extends ListRecords
{
    protected static string $resource = ServerInstanceResource::class;

    public function getSubheading(): ?string
    {
        return __('Manage customer services across your connected servers.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('同步实例')->icon('ri-refresh-line')
                ->visible(fn () => auth()->user()->hasPermission('admin.services.view'))
                ->action(function () {
                    $count = 0;
                    foreach (Server::where('extension', 'Clicd')->where('enabled', true)->get() as $server) {
                        try {
                            $client = ExtensionHelper::getExtension('server', 'Clicd', $server->settings)->getClient();
                            $instances = collect($client->request('GET', 'containers'))->keyBy('uuid');
                            $services = ServerInstanceResource::getEloquentQuery()->whereHas('product', fn ($query) => $query->where('server_id', $server->id))->get();
                            foreach ($services as $service) {
                                $uuid = $service->properties->firstWhere('key', 'clicd_instance_uuid')?->value;
                                if ($uuid && isset($instances[$uuid])) {
                                    (new LifecycleService($client))->sync($service, $instances[$uuid]);
                                    $count++;
                                } elseif ($uuid) {
                                    $service->properties()->updateOrCreate(['key' => 'clicd_status'], ['name' => '实例状态', 'value' => 'missing']);
                                }
                            }
                        } catch (\Exception $e) {
                            report($e);
                            Notification::make()->title($server->name . ' 同步失败')->body('请检查节点连接和 API 权限；保留的数据为上次同步结果。')->danger()->send();
                        }
                    }
                    Notification::make()->title('已同步 ' . $count . ' 台实例')->success()->send();
                }),
            Action::make('create')
                ->label(__('Create service'))
                ->url(ServiceResource::getUrl('create'))
                ->visible(ServiceResource::canCreate()),
        ];
    }
}
