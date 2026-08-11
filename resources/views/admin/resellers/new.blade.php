@extends('layouts.admin')

@section('title')
    Create Reseller
@endsection

@section('content-header')
    <h1>Create Reseller<small>Promote an existing account to a reseller.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.resellers') }}">Resellers</a></li>
        <li class="active">Create</li>
    </ol>
@endsection

@section('content')
<form method="post" action="{{ route('admin.resellers.new') }}">
    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Identity</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">Organisation name</label>
                        <input type="text" name="name" value="{{ old('name') }}" class="form-control" />
                    </div>
                    <div class="form-group">
                        <label class="control-label">Owning account</label>
                        <select name="user_id" class="form-control">
                            @foreach($candidates as $candidate)
                                <option value="{{ $candidate->id }}" @selected((int) old('user_id') === $candidate->id)>{{ $candidate->email }} ({{ $candidate->username }})</option>
                            @endforeach
                        </select>
                        <p class="text-muted small">
                            Administrators, existing resellers and accounts already managed by a reseller are not listed.
                            This account gains access to <code>/reseller</code>.
                        </p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Enabled</label>
                        <select name="enabled" class="form-control">
                            <option value="1" selected>@lang('strings.yes')</option>
                            <option value="0">@lang('strings.no')</option>
                        </select>
                        <p class="text-muted small">Disabling revokes reseller-panel access but keeps all data intact.</p>
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
                    <select name="node_ids[]" class="form-control" multiple size="10">
                        @foreach($nodes as $node)
                            <option value="{{ $node->id }}" @selected(in_array($node->id, old('node_ids', [])))>{{ $node->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-muted small">The reseller can only deploy servers to the nodes selected here.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Resource allowance</h3>
                </div>
                @include('admin.resellers.partials.quota-fields', ['reseller' => null])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <input type="submit" class="btn btn-success pull-right" value="Create Reseller" />
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
