<?php

namespace Paymenter\Extensions\Servers\Clicd\Livewire;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Livewire\Attributes\Locked;
use Livewire\Component;
use OwenIt\Auditing\Events\AuditCustom;
use Paymenter\Extensions\Servers\Clicd\Services\LifecycleService;
use RuntimeException;

class Manage extends Component
{
    #[Locked]
    public Service $service;

    #[Locked]
    public bool $admin = false;

    #[Locked]
    public array $instance = [];

    #[Locked]
    public array $usage = [];

    #[Locked]
    public array $traffic = [];

    #[Locked]
    public ?array $task = null;

    #[Locked]
    public string $error = '';

    #[Locked]
    public string $notice = '';

    #[Locked]
    public string $updatedAt = '';

    public function mount(): void
    {
        $this->checkAccess();
    }

    protected function checkAccess(bool $write = false): void
    {
        $this->service->refresh();
        $user = auth()->user();
        abort_unless($user, 401);
        abort_unless($this->service->product->server?->extension === 'Clicd', 404);
        if ($this->admin) {
            abort_unless($user->hasPermission('admin.services.' . ($write ? 'update' : 'view')), 403);
        } else {
            abort_unless($this->service->user_id === $user->id, 403);
            abort_unless($this->service->status === Service::STATUS_ACTIVE, 403);
        }
    }

    protected function extension()
    {
        $server = $this->service->product->server;

        return ExtensionHelper::getExtension('server', 'Clicd', $server->settings);
    }

    protected function audit(string $action): void
    {
        $this->service->auditEvent = 'extension_action';
        $this->service->isCustomEvent = true;
        $this->service->auditCustomOld = [];
        $this->service->auditCustomNew = ['action' => 'clicd.' . $action];
        Event::dispatch(new AuditCustom($this->service));
    }

    protected function run(callable $action, bool $write = false): void
    {
        $this->error = '';
        $this->notice = '';
        try {
            if ($write && !$this->admin) {
                $instance = $this->extension()->getClient()->container(LifecycleService::identifier($this->service));
                if ($instance['policy_blocked'] ?? false) {
                    throw new RuntimeException('该实例已被节点策略暂停，请联系管理员。');
                }
            }
            $action();
        } catch (ConnectionException $e) {
            $this->error = '无法连接服务器节点，请稍后重试或联系管理员。';
        } catch (RequestException $e) {
            $this->error = '节点接口错误（' . $e->response->status() . '）：' . ($e->response->json('message') ?: '请求未完成');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function refreshInstance(): void
    {
        $this->checkAccess();
        $this->run(function () {
            $client = $this->extension()->getClient();
            $id = LifecycleService::identifier($this->service);
            $instance = $client->container($id);
            $lifecycle = new LifecycleService($client);
            $lifecycle->sync($this->service, $instance);
            $this->instance = Arr::only($instance, ['uuid', 'name', 'virtualization', 'status', 'vcpu', 'ram_mb', 'disk_gb', 'template',
                'ip', 'public_ipv4s', 'ipv6', 'ssh_port',
                'network_down_mbps', 'network_up_mbps', 'expires_at']);
            $this->updatedAt = now()->format('H:i:s');
            $this->task = $lifecycle->task($this->service);
            $this->usage = [];
            $this->traffic = [];
            $this->usage = $client->container($id, 'usage');
            $this->traffic = $client->container($id, 'traffic');
        });
    }

    public function power(string $action): void
    {
        $this->checkAccess(true);
        abort_unless(in_array($action, ['start', 'stop', 'restart'], true), 422);
        $this->run(function () use ($action) {
            $lifecycle = new LifecycleService($this->extension()->getClient());
            $lifecycle->power($this->service, $action, false);
            $this->task = $lifecycle->task($this->service);
            $this->notice = '操作已提交，节点正在执行。请刷新查看任务结果。';
            $this->audit($action);
        }, true);
    }

    public function console(): ?array
    {
        $this->checkAccess(true);
        $result = null;
        $this->run(function () use (&$result) {
            $client = $this->extension()->getClient();
            $id = LifecycleService::identifier($this->service);
            $instance = $client->container($id);
            $data = $client->request('POST', 'sub-user/create', ['container_name' => $instance['name']]);
            if (($data['container_uuids'] ?? []) !== [$instance['uuid']]) {
                throw new RuntimeException('节点返回的控制台账户并非仅绑定当前实例，请管理员检查 实例子账户设置。');
            }
            if (empty($data['access_code']) || empty($data['password'])) {
                throw new RuntimeException('节点未返回控制台访问码或密码。');
            }
            $settings = $this->service->product->server->settings;
            $url = $settings->firstWhere('key', 'panel_url')?->value ?: $settings->firstWhere('key', 'api_url')->value;
            $result = ['url' => rtrim($url, '/') . '/login?code=' . rawurlencode($data['access_code']), 'password' => $data['password']];
        }, true);

        return $result;
    }

    public function render()
    {
        $this->checkAccess();

        return view('clicd::manage', ['canManage' => !$this->admin || auth()->user()->hasPermission('admin.services.update'),
            'natAddress' => $this->service->product->settings->firstWhere('key', 'nat_public_ip')?->value,
            'monthlyTraffic' => (int) ($this->service->product->settings->firstWhere('key', 'monthly_traffic_gb')?->value ?? 0)]);
    }
}
