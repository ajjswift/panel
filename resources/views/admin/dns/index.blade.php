@extends('layouts.admin')

@section('title', 'Managed DNS')

@section('content-header')
    <h1>Managed DNS<small>Approved parent domains and reusable service behavior.</small></h1>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Approved parent domains</h3></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <tr><th>Domain</th><th>Provider health</th><th>Hostnames</th><th>Status</th><th></th></tr>
                    @forelse($domains as $domain)
                        <tr>
                            <td><strong>{{ $domain->name }}</strong><br><code>{{ $domain->domain }}</code></td>
                            <td>{{ $domain->last_provider_status ?? 'Not tested' }}</td>
                            <td>{{ $domain->managed_subdomains_count }}</td>
                            <td>{{ $domain->enabled ? 'Enabled' : 'Disabled' }}</td>
                            <td class="text-right"><a class="btn btn-xs btn-primary" href="{{ route('admin.managed-dns.edit', $domain) }}">Configure</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No parent domains are configured.</td></tr>
                    @endforelse
                </table>
            </div>
            @if($domains->hasPages())
                <div class="box-footer text-center">{{ $domains->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-7">
        <form action="{{ route('admin.managed-dns.store') }}" method="POST">
            @csrf
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Add Cloudflare parent domain</h3></div>
                <div class="box-body">
                    <div class="row">
                        <div class="form-group col-md-6"><label>Display name</label><input name="name" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Root domain</label><input name="domain" class="form-control" placeholder="example.com" required></div>
                        <div class="form-group col-md-6"><label>Cloudflare zone ID</label><input name="zone_id" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Scoped API token</label><input name="api_token" type="password" autocomplete="new-password" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Default TTL</label><input name="ttl" type="number" min="60" value="300" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Label pattern</label><input name="label_pattern" value="^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$" class="form-control" required></div>
                        <div class="form-group col-xs-12"><label>Reserved labels</label><textarea name="reserved_labels" class="form-control" placeholder="www, mail, api, admin, panel, status"></textarea></div>
                        <div class="form-group col-xs-12"><label>Description</label><textarea name="description" class="form-control"></textarea></div>
                    </div>
                    <input type="hidden" name="supports_direct_dns" value="1">
                    <input type="hidden" name="supports_srv" value="1">
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Save disabled domain</button></div>
            </div>
        </form>
    </div>

    <div class="col-md-5">
        <form action="{{ route('admin.managed-dns.profiles.store') }}" method="POST">
            @csrf
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Add service profile</h3></div>
                <div class="box-body">
                    <div class="form-group"><label>Name</label><input name="name" class="form-control" required></div>
                    <div class="form-group"><label>Slug</label><input name="slug" class="form-control" required></div>
                    <div class="row">
                        <div class="form-group col-xs-6"><label>Protocol</label><select name="protocol" class="form-control"><option>tcp</option><option>udp</option><option>http</option><option>https</option></select></div>
                        <div class="form-group col-xs-6"><label>Default port</label><input name="default_port" type="number" class="form-control"></div>
                    </div>
                    <div class="checkbox"><label><input type="checkbox" name="supports_direct_dns" value="1" checked> Direct DNS supported</label></div>
                    <div class="checkbox"><label><input type="checkbox" name="supports_srv" value="1"> SRV supported</label></div>
                    <div class="row">
                        <div class="form-group col-xs-6"><label>SRV service</label><input name="srv_service" placeholder="_minecraft" class="form-control"></div>
                        <div class="form-group col-xs-6"><label>SRV protocol</label><select name="srv_protocol" class="form-control"><option value="">None</option><option>_tcp</option><option>_udp</option></select></div>
                        <input type="hidden" name="srv_priority" value="0"><input type="hidden" name="srv_weight" value="5">
                    </div>
                    <div class="checkbox"><label><input type="checkbox" name="portless_on_default_port" value="1" checked> Hostname alone works on the default port</label></div>
                    <div class="form-group"><label>Description</label><textarea name="description" class="form-control"></textarea></div>
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Create profile</button></div>
            </div>
        </form>

        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Service profiles</h3></div>
            <div class="box-body">
                @foreach($profiles as $profile)
                    <p><strong>{{ $profile->name }}</strong> <span class="label label-default">{{ $profile->protocol }}</span>
                        @if($profile->supports_srv)<span class="label label-info">SRV</span>@endif<br>
                        <small class="text-muted">{{ $profile->description }}</small><br>
                        <a href="{{ route('admin.managed-dns.profiles.edit', $profile) }}">Configure profile</a>
                    </p>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
