@extends('layouts.admin')

@section('title')
    Resellers
@endsection

@section('content-header')
    <h1>Resellers<small>Partners who manage their own users and servers.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Resellers</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Reseller List</h3>
                <div class="box-tools">
                    <a href="{{ route('admin.resellers.new') }}"><button class="btn btn-sm btn-primary">Create New</button></a>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Owner</th>
                            <th class="text-center">Users</th>
                            <th class="text-center">Memory</th>
                            <th class="text-center">Disk</th>
                            <th class="text-center">Servers</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resellers as $item)
                            @php($use = $quota->usage($item))
                            <tr>
                                <td><code>{{ $item->id }}</code></td>
                                <td><a href="{{ route('admin.resellers.view', $item->id) }}">{{ $item->name }}</a></td>
                                <td>{{ $item->owner->email ?? '—' }}</td>
                                <td class="text-center">{{ $item->tenants_count }}</td>
                                <td class="text-center">{{ $use->used('memory') }} / {{ $use->isUnlimited('memory') ? '∞' : $use->limit('memory') }}</td>
                                <td class="text-center">{{ $use->used('disk') }} / {{ $use->isUnlimited('disk') ? '∞' : $use->limit('disk') }}</td>
                                <td class="text-center">{{ $use->used('server_limit') }} / {{ $use->isUnlimited('server_limit') ? '∞' : $use->limit('server_limit') }}</td>
                                <td class="text-center">
                                    @if($item->enabled)
                                        <span class="label label-success">Enabled</span>
                                    @else
                                        <span class="label label-default">Disabled</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($resellers->hasPages())
                <div class="box-footer with-border">
                    <div class="col-md-12 text-center">{!! $resellers->render() !!}</div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
