@extends('layouts.admin')

@section('title', 'DNS Service Profile — ' . $profile->name)

@section('content-header')
    <h1>{{ $profile->name }}<small>Reusable direct-DNS and SRV behavior.</small></h1>
@endsection

@section('content')
<div class="row">
    <div class="col-md-8">
        <form action="{{ route('admin.managed-dns.profiles.update', $profile) }}" method="POST">
            @csrf
            @method('PATCH')
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Service behavior</h3></div>
                <div class="box-body">
                    <div class="row">
                        <div class="form-group col-md-6"><label>Name</label><input name="name" value="{{ old('name', $profile->name) }}" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Slug</label><input name="slug" value="{{ old('slug', $profile->slug) }}" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Protocol</label><select name="protocol" class="form-control">
                            @foreach(['tcp', 'udp', 'http', 'https'] as $protocol)<option value="{{ $protocol }}" @selected($profile->protocol === $protocol)>{{ strtoupper($protocol) }}</option>@endforeach
                        </select></div>
                        <div class="form-group col-md-6"><label>Expected default port</label><input name="default_port" type="number" min="1" max="65535" value="{{ old('default_port', $profile->default_port) }}" class="form-control"></div>
                        <div class="checkbox col-md-4"><label><input name="supports_direct_dns" type="checkbox" value="1" @checked($profile->supports_direct_dns)> Direct DNS supported</label></div>
                        <div class="checkbox col-md-4"><label><input name="supports_srv" type="checkbox" value="1" @checked($profile->supports_srv)> SRV discovery supported</label></div>
                        <div class="checkbox col-md-4"><label><input name="portless_on_default_port" type="checkbox" value="1" @checked($profile->portless_on_default_port)> Portless on default port</label></div>
                        <div class="form-group col-md-3"><label>SRV service</label><input name="srv_service" value="{{ old('srv_service', $profile->srv_service) }}" placeholder="_minecraft" class="form-control"></div>
                        <div class="form-group col-md-3"><label>SRV protocol</label><select name="srv_protocol" class="form-control">
                            <option value="">None</option><option value="_tcp" @selected($profile->srv_protocol === '_tcp')>_tcp</option><option value="_udp" @selected($profile->srv_protocol === '_udp')>_udp</option>
                        </select></div>
                        <div class="form-group col-md-3"><label>Priority</label><input name="srv_priority" type="number" min="0" max="65535" value="{{ old('srv_priority', $profile->srv_priority) }}" class="form-control" required></div>
                        <div class="form-group col-md-3"><label>Weight</label><input name="srv_weight" type="number" min="0" max="65535" value="{{ old('srv_weight', $profile->srv_weight) }}" class="form-control" required></div>
                        <div class="form-group col-xs-12"><label>Description</label><textarea name="description" class="form-control">{{ old('description', $profile->description) }}</textarea></div>
                    </div>
                    <div class="alert alert-info">A service profile describes client behavior. An A, AAAA, or CNAME record is never presented as port forwarding.</div>
                </div>
                <div class="box-footer">
                    <a href="{{ route('admin.managed-dns') }}" class="btn btn-default">Back</a>
                    <button class="btn btn-primary pull-right">Save service profile</button>
                </div>
            </div>
        </form>
    </div>
    @if(!$profile->is_system && !$profile->eggs()->exists() && !$profile->managedSubdomains()->exists())
        <div class="col-md-4">
            <form action="{{ route('admin.managed-dns.profiles.delete', $profile) }}" method="POST">
                @csrf
                @method('DELETE')
                <div class="box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Remove unused profile</h3></div>
                    <div class="box-body"><p>This is allowed only while no egg or managed hostname uses the profile.</p></div>
                    <div class="box-footer"><button class="btn btn-danger">Delete profile</button></div>
                </div>
            </form>
        </div>
    @endif
</div>
@endsection
