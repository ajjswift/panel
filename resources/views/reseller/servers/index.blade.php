@extends('layouts.reseller')

@section('title')
    Servers
@endsection

@section('content-header')
    <h1>Servers<small>Servers belonging to your users.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('reseller.index') }}">Reseller</a></li>
        <li class="active">Servers</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    Server List
                    <small>{{ $usage->used('server_limit') }} of {{ $usage->isUnlimited('server_limit') ? 'unlimited' : $usage->limit('server_limit') }}</small>
                </h3>
                <div class="box-tools search01">
                    <form action="{{ route('reseller.servers') }}" method="GET">
                        <div class="input-group input-group-sm">
                            <input type="text" name="filter[name]" class="form-control pull-right" value="{{ request()->input('filter.name') }}" placeholder="Search">
                            <div class="input-group-btn">
                                <button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
                                <a href="{{ route('reseller.servers.new') }}"><button type="button" class="btn btn-sm btn-primary" style="border-radius: 0 3px 3px 0;margin-left:-1px;">Create New</button></a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Server Name</th>
                            <th>Owner</th>
                            <th>Node</th>
                            <th>Connection</th>
                            <th class="text-center">Memory</th>
                            <th class="text-center">Disk</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td><code>{{ $server->id }}</code></td>
                                <td><a href="{{ route('reseller.servers.view', $server->id) }}">{{ $server->name }}</a></td>
                                <td><a href="{{ route('reseller.users.view', $server->user->id) }}">{{ $server->user->email }}</a></td>
                                <td>{{ $server->node->name }}</td>
                                <td><code>{{ $server->allocation->alias ?? '' }}:{{ $server->allocation->port ?? '' }}</code></td>
                                <td class="text-center">{{ $server->memory === 0 ? '∞' : $server->memory }}</td>
                                <td class="text-center">{{ $server->disk === 0 ? '∞' : $server->disk }}</td>
                                <td class="text-center">
                                    @if($server->isSuspended())
                                        <span class="label label-warning">Suspended</span>
                                    @elseif(!$server->isInstalled())
                                        <span class="label label-default">Installing</span>
                                    @else
                                        <span class="label label-success">Active</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($servers->hasPages())
                <div class="box-footer with-border">
                    <div class="col-md-12 text-center">{!! $servers->render() !!}</div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
