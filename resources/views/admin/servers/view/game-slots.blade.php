@extends('layouts.admin')

@section('title')
    Server — {{ $server->name }}: Game Slots
@endsection

@section('content-header')
    <h1>{{ $server->name }}<small>Inspect and recover game switching state.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.servers') }}">Servers</a></li>
        <li><a href="{{ route('admin.servers.view', $server->id) }}">{{ $server->name }}</a></li>
        <li class="active">Game Slots</li>
    </ol>
@endsection

@section('content')
    @include('admin.servers.partials.navigation')

    <div class="row">
        <div class="col-sm-8">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Game Slots</h3></div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tr>
                            <th>Name</th><th>Game (Egg)</th><th>Install</th><th>State</th>
                            <th>Active</th><th>Disk</th><th class="text-right">Actions</th>
                        </tr>
                        @foreach($slots as $slot)
                            <tr>
                                <td>{{ $slot->name }}</td>
                                <td>{{ $slot->egg?->name ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $slot->installation_status)) }}</td>
                                <td>
                                    @if($slot->state === 'normal')
                                        <span class="label label-default">Normal</span>
                                    @elseif($slot->state === 'over_limit')
                                        <span class="label label-warning">Over Limit</span>
                                    @elseif($slot->state === 'disabled')
                                        <span class="label label-danger">Disabled</span>
                                    @elseif($slot->state === 'recovery_required')
                                        <span class="label label-danger">Recovery Required</span>
                                    @elseif($slot->state === 'deleting')
                                        <span class="label label-warning">Deleting</span>
                                    @endif
                                </td>
                                <td>@if($slot->is_active)<span class="label label-success">Active</span>@endif</td>
                                <td>{{ round($slot->disk_usage_bytes / 1024 / 1024, 1) }} MiB</td>
                                <td class="text-right">
                                    @unless($slot->is_active)
                                        <form action="{{ route('admin.servers.view.game-slots.force-active', [$server->id, $slot->id]) }}" method="POST" style="display:inline">
                                            {!! csrf_field() !!}
                                            <button class="btn btn-xs btn-primary" onclick="return confirm('Pin this slot as the active slot? Only do this after confirming its files are the ones currently at the server root.')">Pin Active</button>
                                        </form>
                                        <form action="{{ route('admin.servers.view.game-slots.toggle-disabled', [$server->id, $slot->id]) }}" method="POST" style="display:inline">
                                            {!! csrf_field() !!}
                                            <button class="btn btn-xs btn-default">{{ $slot->state === 'disabled' ? 'Enable' : 'Disable' }}</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <div class="box-footer">
                    <p class="text-muted small no-margin">
                        The slot allowance is set on the <a href="{{ route('admin.servers.view.build', $server->id) }}">Build Configuration</a> tab.
                        Pinning a slot or disabling one never deletes its stored files.
                    </p>
                </div>
            </div>

            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">Recent Switch Operations</h3></div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tr><th>State</th><th>Stage</th><th>Checkpoint</th><th>Rollback</th><th>When</th><th class="text-right">Actions</th></tr>
                        @forelse($operations as $op)
                            <tr>
                                <td>{{ str_replace('_', ' ', $op->state) }}</td>
                                <td>{{ str_replace('_', ' ', $op->current_stage) }}</td>
                                <td>{{ $op->checkpoint }}</td>
                                <td>{{ $op->rollback_state ?? '—' }}</td>
                                <td>{{ $op->created_at?->diffForHumans() }}</td>
                                <td class="text-right">
                                    @if(in_array($op->state, ['failed_rolled_back', 'failed_requires_action']))
                                        <form action="{{ route('admin.servers.view.game-slots.retry', [$server->id, $op->id]) }}" method="POST" style="display:inline">
                                            {!! csrf_field() !!}
                                            <button class="btn btn-xs btn-warning" onclick="return confirm('Re-queue this operation? It will resume from its last completed checkpoint.')">Retry</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No switch operations recorded.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>

        <div class="col-sm-4">
            <div class="box {{ $diagnostics['server_status'] === 'switching_game' ? 'box-warning' : 'box-default' }}">
                <div class="box-header with-border"><h3 class="box-title">Diagnostics</h3></div>
                <div class="box-body">
                    <dl>
                        <dt>Server status</dt><dd>{{ $diagnostics['server_status'] ?? 'normal' }}</dd>
                        <dt>Database active slot</dt><dd>{{ $diagnostics['database_active_slot']['name'] ?? '—' }}</dd>
                        <dt>Current egg / image</dt><dd>#{{ $diagnostics['current_egg_id'] }} · {{ $diagnostics['current_image'] }}</dd>
                        <dt>Node reachable</dt><dd>{{ $diagnostics['node_reachable'] ? 'Yes' : 'No' }}</dd>
                        <dt>Node power state</dt><dd>{{ $diagnostics['node_power_state'] ?? '—' }}</dd>
                        @if(!$diagnostics['node_reachable'])
                            <dt>Node error</dt><dd class="text-danger">{{ $diagnostics['node_error'] }}</dd>
                        @endif
                        @if($diagnostics['latest_operation'])
                            <dt>Latest operation</dt>
                            <dd>
                                {{ str_replace('_', ' ', $diagnostics['latest_operation']['state']) }}
                                @ {{ $diagnostics['latest_operation']['checkpoint'] }}
                                @if($diagnostics['latest_operation']['error_code'])
                                    <br><span class="text-danger">{{ $diagnostics['latest_operation']['error_code'] }}</span>
                                @endif
                            </dd>
                        @endif
                    </dl>
                </div>
                @if($diagnostics['server_status'] === 'switching_game')
                    <div class="box-footer">
                        <form action="{{ route('admin.servers.view.game-slots.clear-lock', $server->id) }}" method="POST">
                            {!! csrf_field() !!}
                            <button class="btn btn-sm btn-danger btn-block" onclick="return confirm('Clear the switch lock and return the server to normal operation? Only do this if you have confirmed the filesystem is consistent.')">Clear Switch Lock</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
