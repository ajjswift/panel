@extends('layouts.reseller')

@section('title')
    Server: {{ $server->name }}
@endsection

@section('content-header')
    <h1>{{ $server->name }}<small>{{ $server->uuid }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('reseller.index') }}">Reseller</a></li>
        <li><a href="{{ route('reseller.servers') }}">Servers</a></li>
        <li class="active">{{ $server->name }}</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-md-6">
        <form action="{{ route('reseller.servers.details', $server->id) }}" method="POST">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Details</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">Server Name</label>
                        <input type="text" name="name" value="{{ old('name', $server->name) }}" class="form-control" />
                    </div>
                    <div class="form-group">
                        <label class="control-label">Server Owner</label>
                        <select name="owner_id" class="form-control">
                            @foreach($owners as $owner)
                                <option value="{{ $owner->id }}" @selected($owner->id === $server->owner_id)>{{ $owner->email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Description</label>
                        <textarea name="description" rows="3" class="form-control">{{ old('description', $server->description) }}</textarea>
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    {!! method_field('PATCH') !!}
                    <input type="submit" class="btn btn-primary btn-sm" value="Update Details" />
                </div>
            </div>
        </form>
    </div>

    <div class="col-md-6">
        <form action="{{ route('reseller.servers.build', $server->id) }}" method="POST">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Resources</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-6">
                        <label class="control-label">Memory (MiB)</label>
                        <input type="text" name="memory" value="{{ old('memory', $server->memory) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Swap (MiB)</label>
                        <input type="text" name="swap" value="{{ old('swap', $server->swap) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Disk (MiB)</label>
                        <input type="text" name="disk" value="{{ old('disk', $server->disk) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">CPU (%)</label>
                        <input type="text" name="cpu" value="{{ old('cpu', $server->cpu) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Block IO Weight</label>
                        <input type="text" name="io" value="{{ old('io', $server->io) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Databases</label>
                        <input type="text" name="database_limit" value="{{ old('database_limit', $server->database_limit) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Allocations</label>
                        <input type="text" name="allocation_limit" value="{{ old('allocation_limit', $server->allocation_limit) }}" class="form-control" />
                    </div>
                    <div class="form-group col-xs-6">
                        <label class="control-label">Backups</label>
                        <input type="text" name="backup_limit" value="{{ old('backup_limit', $server->backup_limit) }}" class="form-control" />
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    {!! method_field('PATCH') !!}
                    <input type="submit" class="btn btn-primary btn-sm" value="Update Resources" />
                </div>
            </div>
        </form>
    </div>

    <div class="col-md-6">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Information</h3>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table">
                    <tr><td>Node</td><td>{{ $server->node->name }}</td></tr>
                    <tr><td>Egg</td><td>{{ $server->egg->name }}</td></tr>
                    <tr><td>Primary Allocation</td><td><code>{{ $server->allocation->ip }}:{{ $server->allocation->port }}</code></td></tr>
                    <tr>
                        <td>Status</td>
                        <td>
                            @if($server->isSuspended())
                                <span class="label label-warning">Suspended</span>
                            @elseif(!$server->isInstalled())
                                <span class="label label-default">Installing</span>
                            @else
                                <span class="label label-success">Active</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Suspension</h3>
            </div>
            <div class="box-body">
                <p class="no-margin">
                    A suspended server is stopped and its owner cannot start it again until you unsuspend it.
                </p>
            </div>
            <div class="box-footer">
                <form action="{{ route('reseller.servers.suspension', $server->id) }}" method="POST">
                    {!! csrf_field() !!}
                    <input type="hidden" name="action" value="{{ $server->isSuspended() ? 'unsuspend' : 'suspend' }}" />
                    <input type="submit" class="btn btn-sm btn-warning pull-right" value="{{ $server->isSuspended() ? 'Unsuspend' : 'Suspend' }} Server" />
                </form>
            </div>
        </div>
    </div>

    <div class="col-xs-12">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">Delete Server</h3>
            </div>
            <div class="box-body">
                <p class="no-margin">
                    This permanently removes the server and all of its files, and returns its resources to your allowance.
                    This cannot be undone.
                </p>
            </div>
            <div class="box-footer">
                <form action="{{ route('reseller.servers.view', $server->id) }}" method="POST">
                    {!! csrf_field() !!}
                    {!! method_field('DELETE') !!}
                    <input type="submit" class="btn btn-sm btn-danger pull-right" value="Delete Server" />
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
