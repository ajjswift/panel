@extends('layouts.reseller')

@section('title')
    Overview
@endsection

@section('content-header')
    <h1>Overview<small>Your resource allowance at a glance.</small></h1>
    <ol class="breadcrumb">
        <li class="active">Overview</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3>{{ $servers }}</h3>
                <p>Servers</p>
            </div>
            <div class="icon"><i class="fa fa-server"></i></div>
            <a href="{{ route('reseller.servers') }}" class="small-box-footer">Manage <i class="fa fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-green">
            <div class="inner">
                <h3>{{ $users }}</h3>
                <p>Users</p>
            </div>
            <div class="icon"><i class="fa fa-users"></i></div>
            <a href="{{ route('reseller.users') }}" class="small-box-footer">Manage <i class="fa fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>{{ $suspended }}</h3>
                <p>Suspended</p>
            </div>
            <div class="icon"><i class="fa fa-pause"></i></div>
            <a href="{{ route('reseller.servers') }}" class="small-box-footer">&nbsp;</a>
        </div>
    </div>
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-purple">
            <div class="inner">
                <h3>{{ $nodes->count() }}</h3>
                <p>Available nodes</p>
            </div>
            <div class="icon"><i class="fa fa-sitemap"></i></div>
            <a href="#" class="small-box-footer">&nbsp;</a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Resource allowance</h3>
            </div>
            <div class="box-body">
                @foreach ([
                    'memory' => ['Memory', 'MiB'],
                    'disk' => ['Disk', 'MiB'],
                    'cpu' => ['CPU', '%'],
                    'server_limit' => ['Servers', ''],
                    'user_limit' => ['Users', ''],
                    'database_limit' => ['Databases', ''],
                    'allocation_limit' => ['Additional ports', ''],
                    'backup_limit' => ['Backups', ''],
                ] as $dimension => $meta)
                    @php($unlimited = $usage->isUnlimited($dimension))
                    <div style="margin-bottom:14px;">
                        <div class="clearfix" style="margin-bottom:4px;">
                            <strong class="pull-left">{{ $meta[0] }}</strong>
                            <span class="pull-right text-muted">
                                {{ $usage->used($dimension) }}{{ $meta[1] }}
                                @if($unlimited)
                                    of unlimited
                                @else
                                    of {{ $usage->limit($dimension) }}{{ $meta[1] }}
                                    <span class="text-sm">({{ $usage->remaining($dimension) }} left)</span>
                                @endif
                            </span>
                        </div>
                        <div class="progress progress-xs" style="margin-bottom:0;">
                            <div class="progress-bar {{ $usage->percentage($dimension) >= 90 ? 'progress-bar-danger' : 'progress-bar-primary' }}"
                                 style="width: {{ $unlimited ? 0 : $usage->percentage($dimension) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Nodes you can deploy to</h3>
            </div>
            <div class="box-body table-responsive no-padding">
                @if($nodes->isEmpty())
                    <p style="padding:15px;" class="text-muted">
                        No nodes have been made available to you yet. Contact the panel administrator.
                    </p>
                @else
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Name</th><th>Location</th><th>Memory</th><th>Disk</th></tr>
                        </thead>
                        <tbody>
                            @foreach($nodes as $node)
                                <tr>
                                    <td>{{ $node->name }}</td>
                                    <td>{{ $node->location->short ?? '—' }}</td>
                                    <td>{{ $node->memory }} MiB</td>
                                    <td>{{ $node->disk }} MiB</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
