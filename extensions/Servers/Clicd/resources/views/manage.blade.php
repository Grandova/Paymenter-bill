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
                <button type="button" class="vm-primary" :disabled="busy" @click="const loginWindow = window.open('about:blank', '_blank'); busy = true; try { if (!access) access = await $wire.console(); copied = false; if (loginWindow && access?.url) { loginWindow.opener = null; loginWindow.location.href = access.url } else if (loginWindow) loginWindow.close() } finally { busy = false }"><x-ri-external-link-line />打开控制台</button>
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
                <div><h3>控制台登录信息</h3><p>登录地址包含访问码，请勿分享。</p></div>
            </div>
            <label>控制台登录地址
                <div class="vm-secret-field">
                    <input type="text" readonly :value="access.url" @click="$el.select()" autocomplete="off" />
                </div>
            </label>
            <label>登录密码
                <div class="vm-secret-field">
                    <input type="text" readonly :value="access.password" @click="$el.select()" autocomplete="off" />
                </div>
            </label>
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
        @php
            $cpuUsage = isset($usage['cpu_usage_pct']) ? (float) $usage['cpu_usage_pct'] : null;
            $memoryBytes = $usage['memory_usage_bytes'] ?? null;
            $diskBytes = $usage['disk_usage_bytes'] ?? null;
            $trafficBytes = isset($traffic['rx_used_bytes'], $traffic['tx_used_bytes']) ? $traffic['rx_used_bytes'] + $traffic['tx_used_bytes'] : null;
            $memoryUsage = $memoryBytes !== null ? $memoryBytes / max(1, $instance['ram_mb'] * 1048576) * 100 : null;
            $diskUsage = $diskBytes !== null ? $diskBytes / max(1, $instance['disk_gb'] * 1073741824) * 100 : null;
            $trafficUsage = $trafficBytes !== null && $monthlyTraffic > 0 ? $trafficBytes / ($monthlyTraffic * 1073741824) * 100 : null;
            $trafficValue = $trafficBytes !== null ? round($trafficBytes / 1073741824, 2).' GB' : '—';
        @endphp
        <div class="vm-stats">
            @foreach([
                ['CPU 使用率', 'cpu', $cpuUsage !== null ? round($cpuUsage, 1).'%' : '—', $cpuUsage !== null ? round($cpuUsage, 1).'% / 100%' : '— / 100%', $cpuUsage],
                ['内存使用', 'ram', $memoryUsage !== null ? round($memoryUsage, 1).'%' : '—', ($memoryBytes !== null ? round($memoryBytes / 1048576).' MB' : '—').' / '.$instance['ram_mb'].' MB', $memoryUsage],
                ['磁盘使用', 'disk', $diskUsage !== null ? round($diskUsage, 1).'%' : '—', ($diskBytes !== null ? round($diskBytes / 1073741824, 2).' GB' : '—').' / '.$instance['disk_gb'].' GB', $diskUsage],
                ['流量使用', 'traffic', $trafficUsage !== null ? round($trafficUsage, 1).'%' : ($monthlyTraffic === 0 ? '不限' : '—'), $trafficValue.' / '.($monthlyTraffic > 0 ? $monthlyTraffic.' GB' : '不限'), $trafficUsage],
            ] as [$label, $icon, $badge, $value, $percent])
            <div class="vm-stat">
                <div class="vm-stat-heading">
                    <span class="vm-stat-icon">
                        @if($icon === 'cpu')<x-ri-cpu-line />
                        @elseif($icon === 'ram')<x-ri-ram-line />
                        @elseif($icon === 'disk')<x-ri-hard-drive-2-line />
                        @else<x-ri-wifi-line />@endif
                    </span>
                    <span class="vm-stat-percent">{{ $badge }}</span>
                </div>
                <span class="vm-stat-title">{{ $label }}</span>
                <div class="vm-meter"><i style="width: {{ min(100, max(0, $percent ?? 0)) }}%"></i></div>
                <strong>{{ $value }}</strong>
            </div>
            @endforeach
        </div>
        <div class="vm-columns">
            <section class="vm-panel">
                <div class="vm-panel-heading">
                    <span class="vm-panel-icon"><x-ri-global-line /></span>
                    <div><h3>网络与系统</h3><p>系统配置和网络连接信息</p></div>
                </div>
                <dl class="vm-network-grid">
                    <div><dt><x-ri-computer-line />操作系统</dt><dd>{{ $instance['template'] }}</dd></div>
                    <div><dt><x-ri-global-line />公网 IPv4</dt><dd>{{ $publicAddress ?: '未分配' }}</dd></div>
                    <div><dt><x-ri-share-forward-2-line />NAT 公网入口</dt><dd>{{ $natAddress ?: '未配置' }}</dd></div>
                    <div><dt><x-ri-global-line />IPv6</dt><dd>{{ $instance['ipv6'] ?: '未分配' }}</dd></div>
                    <div><dt><x-ri-router-line />内网地址</dt><dd>{{ data_get($instance, 'ip') ?: '未分配' }}</dd></div>
                    <div class="vm-network-wide">
                        <dt><x-ri-terminal-box-line />SSH 连接</dt>
                        <dd class="vm-connection">
                            {{ $sshHost && $instance['ssh_port'] ? $sshHost.':'.$instance['ssh_port'] : '暂不可用' }}
                            @if($sshHost && $instance['ssh_port'])
                            <button type="button" @click="await navigator.clipboard.writeText('{{ $sshHost.':'.$instance['ssh_port'] }}'); copied = true" x-text="copied ? '已复制' : '复制'"></button>
                            @endif
                        </dd>
                    </div>
                    <div class="vm-network-wide"><dt><x-ri-speed-up-line />带宽</dt><dd>下载 {{ $instance['network_down_mbps'] ?? '—' }} / 上传 {{ $instance['network_up_mbps'] ?? '—' }} Mbps</dd></div>
                </dl>
            </section>
            <section class="vm-panel">
                <div class="vm-panel-heading">
                    <span class="vm-panel-icon"><x-ri-bill-line /></span>
                    <div><h3>服务与账单</h3><p>套餐信息、服务状态和续费时间</p></div>
                </div>
                <dl>
                    <div><dt><x-ri-box-3-line />套餐</dt><dd>{{ $service->plan->name }}</dd></div>
                    <div><dt><x-ri-wallet-3-line />续费价格</dt><dd class="vm-billing-price">{{ $service->formattedPrice }}</dd></div>
                    <div><dt><x-ri-checkbox-circle-line />服务状态</dt><dd><span class="vm-service-status {{ $service->status === 'active' ? 'is-active' : '' }}">{{ __('services.statuses.'.$service->status) }}</span></dd></div>
                    <div><dt><x-ri-calendar-line />下次付款</dt><dd>{{ $service->expires_at?->translatedFormat(__('general.date_format')) ?? '长期有效' }}</dd></div>
                </dl>
            </section>
        </div>
        @if(!empty($instance['port_mappings']) || $canManage)
        <section class="vm-panel vm-port-mappings">
            <div class="vm-panel-heading">
                <span class="vm-panel-icon"><x-ri-share-forward-2-line /></span>
                <div>
                    <h3>NAT 端口映射</h3>
                    <p>公网端口转发到容器端口；默认 SSH 映射不可删除。</p>
                </div>
                @if(isset($instance['port_mapping_limit']))
                <span class="vm-port-quota">{{ count($instance['port_mappings'] ?? []) }} / {{ $instance['port_mapping_limit'] ?: '不限' }}</span>
                @endif
            </div>
            @if(count($instance['port_mappings'] ?? []))
            <div class="vm-port-table-wrap">
                <table class="vm-port-table">
                    <thead><tr><th>名称</th><th>协议</th><th>公网端口</th><th>容器端口</th><th>操作</th></tr></thead>
                    <tbody>
                        @foreach($instance['port_mappings'] as $index => $mapping)
                        <tr>
                            <td>{{ $mapping['description'] ?? '端口映射' }} @if((int) ($mapping['container_port'] ?? 0) === 22)<span class="vm-port-default">默认 SSH</span>@endif</td>
                            <td>{{ strtoupper($mapping['protocol'] ?? 'tcp') }}</td>
                            <td>{{ $mapping['host_port'] ?? '—' }}</td>
                            <td>{{ $mapping['container_port'] ?? '—' }}</td>
                            <td>
                                @if($canManage && (int) ($mapping['container_port'] ?? 0) !== 22)
                                <button type="button" class="vm-port-delete" wire:click="deletePortMapping({{ $index }})" wire:confirm="确定删除这条端口映射吗？" wire:loading.attr="disabled">删除</button>
                                @elseif((int) ($mapping['container_port'] ?? 0) === 22)
                                <span class="vm-port-locked">不可删除</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <p class="vm-port-empty">暂时没有端口映射。</p>
            @endif
            @if($canManage)
            <form class="vm-port-form" wire:submit="addPortMapping">
                <label>名称<input type="text" wire:model="mappingName" maxlength="50" placeholder="例如：网站 HTTP" /></label>
                <label>协议<select wire:model="mappingProtocol"><option value="tcp">TCP</option><option value="udp">UDP</option></select></label>
                <label>公网端口<input type="number" wire:model="mappingHostPort" min="1" max="65535" required placeholder="手动填写" /></label>
                <label>容器端口<input type="number" wire:model="mappingContainerPort" min="1" max="65535" required placeholder="例如：8080" /></label>
                <button type="submit" class="vm-primary" wire:loading.attr="disabled" wire:target="addPortMapping">添加映射</button>
            </form>
            @endif
        </section>
        @endif
    @endif
    <footer class="vm-footer">{{ $updatedAt ? '最近同步于 '.$updatedAt : '尚未同步' }} · 运行状态与服务状态分别显示</footer>
</div>
