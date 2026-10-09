<?php

namespace Paymenter\Extensions\Servers\Clicd\Services;

use App\Models\Service;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Clicd\Client\ClicdClient;
use RuntimeException;

class LifecycleService
{
    public function __construct(protected ClicdClient $client) {}

    public static function identifier(Service $service): string
    {
        $id = $service->properties()->where('key', 'clicd_instance_uuid')->value('value');
        if (!$id) {
            throw new RuntimeException('实例尚未开通或未绑定，请联系管理员处理。');
        }

        return $id;
    }

    public function createServer(Service $service, array $settings, array $properties): array
    {
        return Cache::lock('clicd.create.' . $service->id, 180)->block(5, function () use ($service, $settings, $properties) {
            $properties = $service->properties()->pluck('value', 'key')->all() + $properties;
            if (!empty($properties['clicd_instance_uuid'])) {
                $instance = $this->client->container($properties['clicd_instance_uuid']);
                $this->sync($service, $instance);

                return [];
            }

            $name = $properties['clicd_instance_name'] ?? 'pm-' . $service->id . '-' . Str::lower(Str::random(8));
            if (isset($properties['clicd_create_attempt'])) {
                // A timed-out create may have succeeded remotely. Recover only the reserved name; never create a second instance.
                $instance = $this->client->container($name);
                $this->sync($service, $instance);

                return [];
            }
            $type = $settings['virtualization'] ?? 'lxc';
            if (!in_array($type, ['lxc', 'kvm'], true)) {
                throw new RuntimeException('不支持该虚拟化类型');
            }
            $images = $this->client->request('GET', 'images/enabled', ['type' => $type]);
            $image = $properties['os'] ?? $settings['template_id'] ?? '';
            if (!in_array($image, array_column($images, 'id'), true)) {
                throw new RuntimeException('所选镜像未下载或未启用，请检查 服务器节点。');
            }
            $payload = ['name' => $name, 'virtualization' => $type, 'template_id' => $image, 'ssh_auth_mode' => 'auto_password',
                'traffic_mode' => 'total', 'expires_at' => $service->expires_at?->endOfDay()->toIso8601String() ?? ''];
            foreach (['vcpu' => 1, 'ram_mb' => 512, 'disk_gb' => 10, 'network_down_mbps' => 100, 'network_up_mbps' => 100,
                'io_read_mbps' => 0, 'io_write_mbps' => 0, 'monthly_traffic_gb' => 1000, 'port_mapping_count' => 2, 'snapshot_limit' => 1] as $key => $default) {
                $payload[$key] = (int) ($settings[$key] ?? $default);
            }
            foreach (['assign_nat' => true, 'assign_ipv4' => false, 'assign_ipv6' => false] as $key => $default) {
                $payload[$key] = (bool) ($settings[$key] ?? $default);
            }
            $service->properties()->updateOrCreate(['key' => 'clicd_instance_name'], ['name' => '实例名称', 'value' => $name]);
            $service->properties()->updateOrCreate(['key' => 'clicd_create_attempt'], ['name' => '开通请求时间', 'value' => now()->toIso8601String()]);
            try {
                $this->client->request('POST', 'containers', $payload, 100);
            } catch (RequestException $e) {
                if ($e->response->clientError()) {
                    $service->properties()->where('key', 'clicd_create_attempt')->delete();
                }
                throw $e;
            }
            $instance = $this->client->container($name);
            $this->sync($service, $instance);

            return [];
        });
    }

    public function sync(Service $service, array $instance): void
    {
        if (empty($instance['uuid']) || empty($instance['name'])) {
            throw new RuntimeException('实例响应缺少 UUID 或名称');
        }
        $current = $service->properties()->where('key', 'clicd_instance_uuid')->value('value');
        if ($current && $current !== $instance['uuid']) {
            throw new RuntimeException('节点返回的实例与当前服务不匹配');
        }
        $data = [
            'clicd_instance_uuid' => $instance['uuid'], 'clicd_instance_id' => $instance['id'], 'clicd_instance_name' => $instance['name'],
            'clicd_status' => $instance['status'], 'clicd_virtualization' => $instance['virtualization'] ?? 'lxc',
            'clicd_vcpu' => $instance['vcpu'], 'clicd_ram_mb' => $instance['ram_mb'], 'clicd_disk_gb' => $instance['disk_gb'],
            'clicd_template' => $instance['template'], 'clicd_ip' => implode(', ', array_column($instance['public_ipv4s'] ?? [], 'address')),
            'clicd_ipv6' => $instance['ipv6'] ?? '', 'clicd_synced_at' => now()->toIso8601String(),
        ];
        foreach ($data as $key => $value) {
            $service->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => (string) $value]);
        }
    }

    public function power(Service $service, string $action, bool $wait = true): void
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'delete'], true)) {
            throw new RuntimeException('不支持该操作');
        }
        Cache::lock('clicd.action.' . $service->id, 110)->block(3, function () use ($service, $action, $wait) {
            $task = $service->properties()->where('key', 'clicd_task')->value('value');
            if ($task) {
                $this->task($service);
                if ($service->properties()->where('key', 'clicd_task')->exists()) {
                    throw new RuntimeException('实例正在执行任务，请等待任务完成。');
                }
            }
            $result = $this->client->container(self::identifier($service), $action, $action === 'delete' ? 'DELETE' : 'POST');
            $this->storeTask($service, $result);
            if ($wait) {
                for ($i = 0; $i < 20; $i++) {
                    sleep(2);
                    if ($this->task($service) === null) {
                        return;
                    }
                }
                throw new RuntimeException('节点任务仍在执行，请在实例页面查看结果。');
            }
        });
    }

    public function storeTask(Service $service, array $result): void
    {
        if (empty($result['task_id'])) {
            throw new RuntimeException('节点未返回任务 ID');
        }
        $service->properties()->updateOrCreate(['key' => 'clicd_task'], ['name' => '服务器执行任务', 'value' => $result['task_id']]);
    }

    public function task(Service $service): ?array
    {
        $id = $service->properties()->where('key', 'clicd_task')->value('value');
        if (!$id) {
            return null;
        }
        $task = collect($this->client->request('GET', 'tasks'))->firstWhere('id', $id);
        if (!$task) {
            throw new RuntimeException('节点未返回任务 ' . $id . '，请管理员核对任务记录。');
        }
        if (in_array($task['status'], ['done', 'failed'], true)) {
            $service->properties()->where('key', 'clicd_task')->delete();
            if ($task['status'] === 'failed') {
                throw new RuntimeException('节点任务失败：' . ($task['error'] ?? $id));
            }

            return null;
        }

        return Arr::only($task, ['id', 'type', 'status', 'stage_detail']);
    }
}
