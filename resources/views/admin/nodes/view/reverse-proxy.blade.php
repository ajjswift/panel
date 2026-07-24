@extends('layouts.admin')

@section('title')
    {{ $node->name }}: Reverse Proxy
@endsection

@section('content-header')
    <h1>{{ $node->name }}<small>Give web servers on this node clean HTTPS addresses.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li><a href="{{ route('admin.nodes.view', $node->id) }}">{{ $node->name }}</a></li>
        <li class="active">Reverse Proxy</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="nav-tabs-custom nav-tabs-floating">
            <ul class="nav nav-tabs">
                <li><a href="{{ route('admin.nodes.view', $node->id) }}">About</a></li>
                <li><a href="{{ route('admin.nodes.view.settings', $node->id) }}">Settings</a></li>
                <li><a href="{{ route('admin.nodes.view.configuration', $node->id) }}">Configuration</a></li>
                <li class="active"><a href="{{ route('admin.nodes.view.reverse-proxy', $node->id) }}">Reverse Proxy</a></li>
                <li><a href="{{ route('admin.nodes.view.allocation', $node->id) }}">Allocation</a></li>
                <li><a href="{{ route('admin.nodes.view.servers', $node->id) }}">Servers</a></li>
            </ul>
        </div>
    </div>

    <div class="col-md-6">
        <form action="{{ route('admin.nodes.view.reverse-proxy.update', $node->id) }}" method="POST">
            @csrf
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Settings</h3></div>
                <div class="box-body">
                    <div class="form-group">
                        <input type="hidden" name="reverse_proxy_enabled" value="0">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="rpEnabled" type="checkbox" name="reverse_proxy_enabled" value="1" @checked($node->reverse_proxy_enabled)>
                            <label for="rpEnabled" class="strong">Enable the reverse proxy on this node</label>
                        </div>
                        <p class="help-block">Lets customers give web servers here a clean <code>https://name.domain</code> address.</p>
                    </div>
                    <div class="form-group">
                        <label>Base domain</label>
                        <select name="reverse_proxy_base_domain_id" class="form-control">
                            <option value="">— Select a domain —</option>
                            @foreach($domains as $domain)
                                <option value="{{ $domain->id }}" @selected($node->reverse_proxy_base_domain_id === $domain->id)>{{ $domain->domain }}</option>
                            @endforeach
                        </select>
                        <p class="help-block">This node's agent will live at <code>{{ \Illuminate\Support\Str::slug($node->name) }}.<em>domain</em></code>.</p>
                    </div>
                    <div class="form-group">
                        <label>Control port</label>
                        <input type="number" name="reverse_proxy_control_port" class="form-control" value="{{ $node->reverse_proxy_control_port }}" min="1" max="65535">
                        <p class="help-block">Port the panel uses to reach the agent. Must be open on the node's firewall (default 8443).</p>
                    </div>
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Save</button></div>
            </div>
        </form>
    </div>

    <div class="col-md-6">
        <div class="box {{ $node->reverse_proxy_enabled ? 'box-success' : 'box-default' }}">
            <div class="box-header with-border"><h3 class="box-title">Agent status</h3></div>
            <div class="box-body">
                @if(!$node->reverse_proxy_enabled)
                    <p class="text-muted">The reverse proxy is turned off for this node.</p>
                @else
                    <dl class="dl-horizontal" style="margin-bottom:0">
                        <dt>Hostname</dt>
                        <dd><code>{{ $node->reverseProxyHostname() ?? '—' }}</code></dd>
                        <dt>Status</dt>
                        <dd>
                            @php($stale = $node->reverse_proxy_last_seen_at && $node->reverse_proxy_last_seen_at->diffInSeconds() > $offlineAfter)
                            @if(!$node->reverse_proxy_last_seen_at)
                                <span class="label label-warning">Waiting for first check-in</span>
                            @elseif($stale || $node->reverse_proxy_status !== 'online')
                                <span class="label label-danger">Offline</span>
                            @else
                                <span class="label label-success">Online</span>
                            @endif
                        </dd>
                        <dt>Version</dt>
                        <dd>{{ $node->reverse_proxy_agent_version ?? '—' }}</dd>
                        <dt>Last seen</dt>
                        <dd>{{ $node->reverse_proxy_last_seen_at?->diffForHumans() ?? 'Never' }}</dd>
                    </dl>
                @endif
            </div>
        </div>

        @if($node->reverse_proxy_enabled)
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">Install on the node</h3></div>
                <div class="box-body">
                    @if($installCommand)
                        <p class="text-muted">Run this once on the node as root. It installs the agent, sets up automatic HTTPS certificates, and starts it as a service.</p>
                        <pre style="white-space:pre-wrap;word-break:break-all">{{ $installCommand }}</pre>
                        <form action="{{ route('admin.nodes.view.reverse-proxy.rotate', $node->id) }}" method="POST" onsubmit="return confirm('Generate new keys? You will need to re-run the install command on the node.')">
                            @csrf
                            <button class="btn btn-sm btn-default">Generate new keys</button>
                        </form>
                    @else
                        <p class="text-muted">Save a base domain above to generate the install command.</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
