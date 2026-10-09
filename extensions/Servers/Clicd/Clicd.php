<?php

namespace Paymenter\Extensions\Servers\Clicd;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Service\Updated;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Clicd\Client\ClicdClient;
use Paymenter\Extensions\Servers\Clicd\Jobs\SyncExpiry;
use Paymenter\Extensions\Servers\Clicd\Livewire\Manage;
use Paymenter\Extensions\Servers\Clicd\Services\LifecycleService;

#[ExtensionMeta(name: 'Clicd', description: 'CLICD 云服务器：LXC / KVM 实例管理', version: 'builtin', author: 'CLICD / Paymenter-bill')]
class Clicd extends Server
{
    public function boot()
    {
        View::addNamespace('clicd', __DIR__ . '/resources/views');
        Livewire::component('clicd.manage', Manage::class);
        Event::listen(Updated::class, function (Updated $event) {
            $service = $event->service;
            if ($service->wasChanged('expires_at') && $service->product->server?->extension === 'Clicd'
                && $service->properties()->where('key', 'clicd_instance_uuid')->exists()) {
                SyncExpiry::dispatch($service)->afterCommit();
            }
        });
    }

    public function getConfig($values = [])
    {
        return [
            ['name' => 'api_url', 'type' => 'text', 'label' => 'CLICD 节点地址', 'description' => '例如 https://node.example.com，不要添加 /api/v1。', 'required' => true, 'validation' => 'required|url'],
            ['name' => 'panel_url', 'type' => 'text', 'label' => 'CLICD 控制台地址', 'description' => '客户访问的控制台网址；留空使用节点地址。', 'validation' => 'nullable|url'],
            ['name' => 'api_key', 'type' => 'password', 'label' => 'API 密钥', 'encrypted' => true, 'required' => true],
            ['name' => 'verify_ssl', 'type' => 'checkbox', 'label' => '验证 HTTPS 证书', 'default' => true],
        ];
    }

    public function testConfig()
    {
        $this->getClient()->request('GET', 'containers');

        return true;
    }

    public function getProductConfig($values = [])
    {
        return [
            ['name' => 'region', 'type' => 'text', 'label' => '地区名称', 'description' => '例如：中国香港。一个地区产品绑定一个节点。'],
            ['name' => 'virtualization', 'type' => 'select', 'label' => '虚拟化类型', 'options' => ['lxc' => 'LXC 容器', 'kvm' => 'KVM 虚拟机'], 'default' => 'lxc', 'required' => true],
            ['name' => 'template_id', 'type' => 'text', 'label' => '默认镜像 ID', 'description' => '填写节点中已下载并启用的镜像 ID。', 'required' => true],
            ['name' => 'vcpu', 'type' => 'number', 'label' => 'CPU 核心', 'default' => 1, 'required' => true, 'validation' => 'required|integer|min:1'],
            ['name' => 'ram_mb', 'type' => 'number', 'label' => '内存（MB）', 'default' => 512, 'required' => true, 'validation' => 'required|integer|min:128'],
            ['name' => 'disk_gb', 'type' => 'number', 'label' => '磁盘（GB）', 'default' => 10, 'required' => true, 'validation' => 'required|integer|min:1'],
            ['name' => 'network_down_mbps', 'type' => 'number', 'label' => '下载带宽（Mbps）', 'default' => 100, 'validation' => 'nullable|integer|min:0'],
            ['name' => 'network_up_mbps', 'type' => 'number', 'label' => '上传带宽（Mbps）', 'default' => 100, 'validation' => 'nullable|integer|min:0'],
            ['name' => 'monthly_traffic_gb', 'type' => 'number', 'label' => '每月双向流量（GB，0 为不限）', 'default' => 1000, 'validation' => 'nullable|integer|min:0'],
            ['name' => 'io_read_mbps', 'type' => 'number', 'label' => '磁盘读取（MB/s，0 为不限）', 'default' => 0, 'validation' => 'nullable|integer|min:0'],
            ['name' => 'io_write_mbps', 'type' => 'number', 'label' => '磁盘写入（MB/s，0 为不限）', 'default' => 0, 'validation' => 'nullable|integer|min:0'],
            ['name' => 'assign_nat', 'type' => 'checkbox', 'label' => '启用 NAT 端口映射', 'default' => true],
            ['name' => 'nat_public_ip', 'type' => 'text', 'label' => 'NAT 公网入口', 'description' => '填写客户连接使用的公网 IP 或域名。'],
            ['name' => 'port_mapping_count', 'type' => 'number', 'label' => 'NAT 端口配额', 'default' => 2, 'validation' => 'nullable|integer|min:0|max:64'],
            ['name' => 'assign_ipv4', 'type' => 'checkbox', 'label' => '分配独立 IPv4', 'default' => false],
            ['name' => 'assign_ipv6', 'type' => 'checkbox', 'label' => '分配 IPv6', 'default' => false],
            ['name' => 'snapshot_limit', 'type' => 'number', 'label' => '快照配额', 'default' => 1, 'validation' => 'required|integer|min:1|max:10'],
        ];
    }

    public function getDownloadedImageOptions(Product $product, array $settings = []): array
    {
        $type = $settings['virtualization'] ?? 'lxc';
        if (!in_array($type, ['lxc', 'kvm'], true)) {
            throw new \RuntimeException('不支持该虚拟化类型');
        }
        $images = Cache::remember('clicd.images.' . $product->server_id . '.' . $type, 30,
            fn () => $this->getClient()->request('GET', 'images/enabled', ['type' => $type]));

        return collect($images)->mapWithKeys(fn ($image) => [$image['id'] => $image['name']])->all();
    }

    public function getCheckoutConfig(Product $product, $values = [], $settings = [])
    {
        return [
            ['name' => 'os', 'type' => 'select', 'label' => '操作系统', 'description' => '仅显示节点已下载并启用的镜像。', 'required' => true,
                'options' => $this->getDownloadedImageOptions($product, $settings), 'default' => $settings['template_id'] ?? null],
        ];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        return (new LifecycleService($this->getClient()))->createServer($service, $settings, $properties);
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        // Expiry is also enforced by CLICD when a customer uses their direct console link.
        $this->getClient()->container(LifecycleService::identifier($service), 'expiry', 'PUT', ['expires_at' => now()->subMinute()->toIso8601String()]);
        (new LifecycleService($this->getClient()))->power($service, 'stop');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $service->refresh();
        $this->getClient()->container(LifecycleService::identifier($service), 'expiry', 'PUT', ['expires_at' => $service->expires_at?->endOfDay()->toIso8601String() ?? '']);
        (new LifecycleService($this->getClient()))->power($service, 'start');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        (new LifecycleService($this->getClient()))->power($service, 'delete');
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $client = $this->getClient();
        $id = LifecycleService::identifier($service);
        $instance = $client->container($id);
        if ((float) ($settings['disk_gb'] ?? $instance['disk_gb']) !== (float) $instance['disk_gb']) {
            throw new \RuntimeException('当前服务器接口不支持磁盘扩容，请管理员处理。');
        }
        if (($settings['virtualization'] ?? 'lxc') !== ($instance['virtualization'] ?? 'lxc')) {
            throw new \RuntimeException('Unsupported：不能通过升级套餐切换虚拟化类型。');
        }
        $client->container($id, 'resource-limit', 'PUT', array_map('intval', Arr::only($settings, ['vcpu', 'ram_mb', 'network_down_mbps', 'network_up_mbps', 'io_read_mbps', 'io_write_mbps'])));
        $client->container($id, 'traffic-limit', 'PUT', ['traffic_mode' => 'total', 'monthly_traffic_gb' => (int) ($settings['monthly_traffic_gb'] ?? 0)]);
    }

    public function syncExpiry(Service $service)
    {
        $service->refresh();
        $expiry = $service->status === Service::STATUS_SUSPENDED ? now()->subMinute()->toIso8601String() : ($service->expires_at?->endOfDay()->toIso8601String() ?? '');
        $this->getClient()->container(LifecycleService::identifier($service), 'expiry', 'PUT', ['expires_at' => $expiry]);
    }

    public function getActions(Service $service, $settings = [], $properties = [])
    {
        return [['type' => 'view', 'name' => 'overview', 'label' => '服务器管理']];
    }

    public function getView(Service $service, $settings, $properties, $view)
    {
        return view('clicd::overview', compact('service'));
    }

    public function getClient(): ClicdClient
    {
        return new ClicdClient(['api_url' => $this->config('api_url'), 'api_key' => $this->config('api_key'), 'verify_ssl' => $this->config('verify_ssl') ?? true]);
    }
}
