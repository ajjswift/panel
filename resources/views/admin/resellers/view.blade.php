@extends('layouts.admin')

@section('title')
    Reseller: {{ $reseller->name }}
@endsection

@section('content-header')
    <h1>{{ $reseller->name }}<small>{{ $reseller->owner->email ?? 'no owner' }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.resellers') }}">Resellers</a></li>
        <li class="active">{{ $reseller->name }}</li>
    </ol>
@endsection

@section('content')
<form method="post" action="{{ route('admin.resellers.view', $reseller->id) }}">
    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Identity</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">Organisation name</label>
                        <input type="text" name="name" value="{{ old('name', $reseller->name) }}" class="form-control" />
                    </div>
                    <div class="form-group">
                        <label class="control-label">Owning account</label>
                        <p class="form-control-static">
                            <a href="{{ route('admin.users.view', $reseller->user_id) }}">{{ $reseller->owner->email ?? '—' }}</a>
                        </p>
                        <p class="text-muted small">The owning account cannot be changed after creation.</p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Enabled</label>
                        <select name="enabled" class="form-control">
                            <option value="1" @selected($reseller->enabled)>@lang('strings.yes')</option>
                            <option value="0" @selected(!$reseller->enabled)>@lang('strings.no')</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Available nodes</h3>
                </div>
                <div class="box-body">
                    @php($granted = $reseller->nodes->pluck('id')->all())
                    <select name="node_ids[]" class="form-control" multiple size="10">
                        @foreach($nodes as $node)
                            <option value="{{ $node->id }}" @selected(in_array($node->id, old('node_ids', $granted)))>{{ $node->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-muted small">Removing a node does not move existing servers; it only stops new deployments there.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Resource allowance</h3>
                    <div class="pull-right text-muted small">
                        In use — memory {{ $usage->used('memory') }} MiB, disk {{ $usage->used('disk') }} MiB,
                        servers {{ $usage->used('server_limit') }}, users {{ $usage->used('user_limit') }}
                    </div>
                </div>
                @include('admin.resellers.partials.quota-fields', ['reseller' => $reseller])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    {!! method_field('PATCH') !!}
                    <input type="submit" class="btn btn-primary pull-right" value="Save Changes" />
                </div>
            </div>
        </div>
    </div>
</form>

<div class="row">
    <div class="col-xs-12">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">Delete Reseller</h3>
            </div>
            <div class="box-body">
                <p class="no-margin">
                    The reseller must have no servers before it can be deleted. Its users are kept and become
                    ordinary panel accounts.
                </p>
            </div>
            <div class="box-footer">
                <form action="{{ route('admin.resellers.view', $reseller->id) }}" method="POST">
                    {!! csrf_field() !!}
                    {!! method_field('DELETE') !!}
                    <input type="submit" class="btn btn-sm btn-danger pull-right" value="Delete Reseller" />
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
