<div class="vm-console" wire:init="refreshInstance" x-data="{ access: null, busy: false, copied: false }">
    @include('clicd::styles')
    @php
        $status = $instance['status'] ?? null;
        $states = ['running' => '运行中', 'stopped' => '已关机', 'starting' => '启动中', 'stopping' => '关机中'];
        $sshHost = data_get($instance, 'ip') ?: $natAddress;
        $publicAddress = implode(' / ', array_column($instance['public_ipv4s'] ?? [], 'address'));
    @endphp

    <section class="vm-overview">
        <header class="vm-header">
            <div class="vm-heading">
                <div class="vm-server-icon"><x-ri-server-line /></div>
                <div>
                    <span class="vm-eyebrow">云服务器 · 实例 #{{ $service->id }}</span>
                    <h2>{{ $instance['name'] ?? '实例 #'.$service->id }}</h2>
                    <p>云服务器</p>
                </div>
            </div>
            <div class="vm-actions">
                <span class="vm-status {{ $status === 'running' ? 'is-running' : '' }}">{{ $states[$status] ?? ($status ?: '等待同步') }}</span>
                <button type="button" wire:click="refreshInstance" wire:loading.attr="disabled"><x-ri-refresh-line />同步状态</button>
                @if($canManage && $instance)
                <button type="button" class="vm-primary" :disabled="busy" @click="busy = true; try { access = await $wire.console(); copied = false } finally { busy = false }"><x-ri-external-link-line />打开控制台</button>
                @endif
            </div>
        </header>
        @if($canManage && $instance)
        <div class="vm-toolbar">
            <span>电源</span>
            <div class="vm-actions">
                <button type="button" class="vm-power-start" wire:click="power('start')" wire:confirm="确定启动这台服务器？" wire:loading.attr="disabled" @disabled($task || in_array($status, ['running', 'starting'], true))><x-ri-play-line />启动</button>
                <button type="button" wire:click="power('restart')" wire:confirm="重启会中断当前连接，确定继续？" wire:loading.attr="disabled" @disabled($task || in_array($status, ['starting', 'stopping'], true))><x-ri-restart-line />重启</button>
                <button type="button" class="vm-danger" wire:click="power('stop')" wire:confirm="关机会中断服务器上的服务，确定继续？" wire:loading.attr="disabled" @disabled($task || in_array($status, ['stopped', 'stopping'], true))><x-ri-shut-down-line />关机</button>
            </div>
        </div>
        @endif
    </section>

    @if($error)<div class="vm-message vm-error" role="alert">{{ $error }}@if($updatedAt) <span>当前保留上次读取的数据，请刷新重试。</span>@endif</div>@endif
    @if($notice)<div class="vm-message" role="status">{{ $notice }}</div>@endif
    @if($task)<div class="vm-message" wire:poll.10s="refreshInstance">任务 {{ $task['id'] }} 正在执行 · {{ $task['stage_detail'] ?? ($task['status'] === 'pending' ? '排队中' : '处理中') }}</div>@endif

    <template x-if="access">
        <section class="vm-panel vm-console-credentials">
            <div class="vm-access-heading">
                <div><h3>控制台登录信息</h3><p>访问码已包含在控制台入口中，请妥善保管登录密码。</p></div>
                <button type="button" class="vm-access-close" @click="access = null" aria-label="收起"><x-ri-close-line /></button>
            </div>
            <label>登录密码<input type="text" readonly :value="access.password" @click="$el.select()" autocomplete="off" /></label>
            <div class="vm-actions">
                <button type="button" @click="await navigator.clipboard.writeText(access.password); copied = true" x-text="copied ? '密码已复制' : '复制密码'"></button>
                <a class="vm-primary" :href="access.url" target="_blank" rel="noopener noreferrer">继续前往控制台 <x-ri-arrow-right-up-line /></a>
            </div>
        </section>
    </template>

    <div class="vm-loading" wire:loading.delay>正在连接服务器，请稍候…</div>
    @if(!$instance)
        <div class="vm-empty"><x-ri-server-line /><h3>等待实例信息</h3><p>开通完成后，这里将显示服务器配置和管理选项。</p></div>
    @else
        <div class="vm-stats">
            @foreach([
                ['处理器', isset($usage['cpu_usage_pct']) ? round($usage['cpu_usage_pct'], 1).'%' : '—', $instance['vcpu'].' 核', $usage['cpu_usage_pct'] ?? 0],
                ['内存', isset($usage['memory_usage_bytes']) ? round($usage['memory_usage_bytes'] / 1048576).' MB' : '—', $instance['ram_mb'].' MB', isset($usage['memory_usage_bytes']) ? $usage['memory_usage_bytes'] / max(1, $instance['ram_mb'] * 1048576) * 100 : 0],
                ['存储', isset($usage['disk_usage_bytes']) ? round($usage['disk_usage_bytes'] / 1073741824, 2).' GB' : $instance['disk_gb'].' GB', '磁盘容量', isset($usage['disk_usage_bytes']) ? $usage['disk_usage_bytes'] / max(1, $instance['disk_gb'] * 1073741824) * 100 : 0],
                ['流量', isset($traffic['rx_used_bytes'], $traffic['tx_used_bytes']) ? round(($traffic['rx_used_bytes'] + $traffic['tx_used_bytes']) / 1073741824, 2).' GB' : '—', '上传与下载', null],
            ] as [$label, $value, $limit, $percent])
            <div class="vm-stat">
                <span>{{ $label }}</span><strong>{{ $value }}</strong><small>{{ $limit }}</small>
                @if($percent !== null)<div class="vm-meter"><i style="width: {{ min(100, max(0, $percent)) }}%"></i></div>@endif
            </div>
            @endforeach
        </div>
        <div class="vm-columns">
            <section class="vm-panel">
                <h3>网络与系统</h3>
                <dl>
                    <div><dt>操作系统</dt><dd>{{ $instance['template'] }}</dd></div>
                    <div><dt>公网 IPv4</dt><dd>{{ $publicAddress ?: '未分配' }}</dd></div>
                    <div><dt>NAT 公网入口</dt><dd>{{ $natAddress ?: '未配置' }}</dd></div>
                    <div><dt>IPv6</dt><dd>{{ $instance['ipv6'] ?: '未分配' }}</dd></div>
                    <div><dt>内网地址</dt><dd>{{ data_get($instance, 'ip') ?: '未分配' }}</dd></div>
                    <div>
                        <dt>SSH 连接</dt>
                        <dd class="vm-connection">
                            {{ $sshHost && $instance['ssh_port'] ? $sshHost.':'.$instance['ssh_port'] : '暂不可用' }}
                            @if($sshHost && $instance['ssh_port'])
                            <button type="button" @click="await navigator.clipboard.writeText('{{ $sshHost.':'.$instance['ssh_port'] }}'); copied = true" x-text="copied ? '已复制' : '复制'"></button>
                            @endif
                        </dd>
                    </div>
                    <div><dt>带宽</dt><dd>下载 {{ $instance['network_down_mbps'] ?? '—' }} / 上传 {{ $instance['network_up_mbps'] ?? '—' }} Mbps</dd></div>
                </dl>
            </section>
            <section class="vm-panel">
                <h3>服务与账单</h3>
                <dl>
                    <div><dt>套餐</dt><dd>{{ $service->plan->name }}</dd></div>
                    <div><dt>续费价格</dt><dd>{{ $service->formattedPrice }}</dd></div>
                    <div><dt>服务状态</dt><dd>{{ __('services.statuses.'.$service->status) }}</dd></div>
                    <div><dt>下次付款</dt><dd>{{ $service->expires_at?->translatedFormat(__('general.date_format')) ?? '长期有效' }}</dd></div>
                </dl>
                <div class="vm-console-access"><p>其他实例管理操作可在服务器控制台中进行。</p></div>
            </section>
        </div>
    @endif
    <footer class="vm-footer">{{ $updatedAt ? '最近同步于 '.$updatedAt : '尚未同步' }} · 运行状态与服务状态分别显示</footer>
</div>
