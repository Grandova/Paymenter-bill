<div class="vm-console" wire:init="refreshInstance" x-data="{ access: null, busy: false, copied: false }">
    @include('clicd::styles')
    @php
        $status = $instance['status'] ?? null;
        $states = ['running' => '运行中', 'stopped' => '已关机', 'starting' => '启动中', 'stopping' => '关机中'];
    @endphp
    <div class="vm-header">
        <div class="vm-heading"><div class="vm-server-icon"><x-ri-server-line /></div><div><span class="vm-eyebrow">云服务器 / #{{ $service->id }}</span><h2>{{ $instance['name'] ?? $service->label }}</h2><p>{{ $service->product->name }} <span>·</span> {{ strtoupper($instance['virtualization'] ?? 'CLICD') }}</p></div></div>
        <div class="vm-actions"><span class="vm-status {{ $status === 'running' ? 'is-running' : '' }}">{{ $states[$status] ?? ($status ?: '待同步') }}</span><button type="button" wire:click="refreshInstance" wire:loading.attr="disabled"><x-ri-refresh-line />刷新</button>@if($canManage && $instance)<button type="button" class="vm-primary" :disabled="busy" @click="busy = true; try { access = await $wire.console(); copied = false } finally { busy = false }"><x-ri-external-link-line />进入控制台</button>@endif</div>
    </div>
    @if($error)<div class="vm-message vm-error" role="alert">{{ $error }}@if($updatedAt) <span>当前保留上次读取的数据，请刷新重试。</span>@endif</div>@endif
    @if($notice)<div class="vm-message" role="status">{{ $notice }}</div>@endif
    @if($task)<div class="vm-message" wire:poll.10s="refreshInstance">任务 {{ $task['id'] }} 正在执行 · {{ $task['stage_detail'] ?? ($task['status'] === 'pending' ? '排队中' : '处理中') }}</div>@endif
    @if($canManage && $instance)
    <div class="vm-toolbar">
        <span>实例操作</span>
        <div class="vm-actions">
            <button type="button" wire:click="power('start')" wire:confirm="确定启动这台服务器？" wire:loading.attr="disabled" @disabled($task || $status === 'running')><x-ri-play-line />开机</button>
            <button type="button" wire:click="power('restart')" wire:confirm="重启会中断当前连接，确定继续？" wire:loading.attr="disabled" @disabled($task)><x-ri-restart-line />重启</button>
            <button type="button" class="vm-danger" wire:click="power('stop')" wire:confirm="关机会中断服务器上的服务，确定继续？" wire:loading.attr="disabled" @disabled($task || $status === 'stopped')><x-ri-shut-down-line />关机</button>
        </div>
    </div>
    @endif
    <template x-if="access"><section class="vm-panel vm-console-credentials">
        <h3>登录实例控制台</h3><p>复制此密码，在新窗口的 CLICD 实例登录页使用。</p>
        <label>控制台登录密码<input type="text" readonly :value="access.password" @click="$el.select()" autocomplete="off" /></label>
        <div class="vm-actions"><button type="button" @click="await navigator.clipboard.writeText(access.password); copied = true" x-text="copied ? '已复制' : '复制密码'"></button><a :href="access.url" target="_blank" rel="noopener noreferrer">打开实例控制台 ↗</a><button type="button" @click="access = null">收起</button></div>
    </section></template>
    <div class="vm-loading" wire:loading.delay>正在连接节点，请稍候…</div>
    @if(!$instance)
        <div class="vm-empty"><x-ri-server-line /><h3>等待实例信息</h3><p>开通完成后，这里将显示服务器配置和管理选项。</p></div>
    @else
        <div class="vm-stats">
            @foreach([
                ['CPU', isset($usage['cpu_usage_pct']) ? round($usage['cpu_usage_pct'], 1).'%' : '—', $instance['vcpu'].' 核', $usage['cpu_usage_pct'] ?? 0],
                ['内存', isset($usage['memory_usage_bytes']) ? round($usage['memory_usage_bytes'] / 1048576).' MB' : '—', $instance['ram_mb'].' MB', isset($usage['memory_usage_bytes']) ? $usage['memory_usage_bytes'] / max(1, $instance['ram_mb'] * 1048576) * 100 : 0],
                ['磁盘', isset($usage['disk_usage_bytes']) ? round($usage['disk_usage_bytes'] / 1073741824, 2).' GB' : '—', $instance['disk_gb'].' GB', isset($usage['disk_usage_bytes']) ? $usage['disk_usage_bytes'] / max(1, $instance['disk_gb'] * 1073741824) * 100 : 0],
                ['本月流量', isset($traffic['rx_used_bytes'], $traffic['tx_used_bytes']) ? round(($traffic['rx_used_bytes'] + $traffic['tx_used_bytes']) / 1073741824, 2).' GB' : '—', '上传 + 下载', null],
            ] as [$label, $value, $limit, $percent])
            <div class="vm-stat"><span>{{ $label }}</span><strong>{{ $value }}</strong><small>{{ $limit }}</small>@if($percent !== null)<div class="vm-meter"><i style="width: {{ min(100, max(0, $percent)) }}%"></i></div>@endif</div>
            @endforeach
        </div>
        <div class="vm-columns">
            <section class="vm-panel"><h3>实例信息</h3><dl>
                <div><dt>操作系统</dt><dd>{{ $instance['template'] }}</dd></div>
                <div><dt>实例名称</dt><dd>{{ $instance['name'] }}</dd></div>
                <div><dt>公网 IPv4</dt><dd>{{ implode(' / ', array_column($instance['public_ipv4s'] ?? [], 'address')) ?: '未分配' }}</dd></div>
                <div><dt>NAT 公网入口</dt><dd>{{ $natAddress ?: '未配置' }}</dd></div>
                <div><dt>IPv6</dt><dd>{{ $instance['ipv6'] ?: '未分配' }}</dd></div>
                <div><dt>内网地址</dt><dd>{{ $instance['ip'] ?: '未分配' }}</dd></div>
                <div><dt>SSH 端口</dt><dd>{{ $instance['ssh_port'] ?: '未分配' }}</dd></div>
                <div><dt>带宽</dt><dd>下载 {{ $instance['network_down_mbps'] ?? '—' }} / 上传 {{ $instance['network_up_mbps'] ?? '—' }} Mbps</dd></div>
            </dl></section>
            <section class="vm-panel"><h3>服务与账单</h3><dl>
                <div><dt>套餐</dt><dd>{{ $service->plan->name }}</dd></div><div><dt>价格</dt><dd>{{ $service->formattedPrice }}</dd></div>
                <div><dt>服务状态</dt><dd>{{ __('services.statuses.'.$service->status) }}</dd></div>
                <div><dt>到期时间</dt><dd>{{ $service->expires_at?->format('Y-m-d') ?? '长期有效' }}</dd></div>
                <div><dt>创建时间</dt><dd>{{ $service->created_at->format('Y-m-d') }}</dd></div>
            </dl>
            <div class="vm-console-access"><h4>更多管理功能</h4><p>点击页面上方「进入控制台」，在 CLICD 中使用终端、重装系统、快照和网络配置。</p></div>
            </section>
        </div>
    @endif
    <footer class="vm-footer">{{ $updatedAt ? '最后同步 '.$updatedAt : '尚未同步' }} · 实例运行状态与账单服务状态分别显示</footer>
</div>
