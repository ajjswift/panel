@extends('layouts.admin')

@section('title', 'Domains — ' . $domain->domain)

@section('content-header')
    <h1>{{ $domain->name }}<small>{{ $domain->domain }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.managed-dns') }}">Domains</a></li>
        <li class="active">{{ $domain->domain }}</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('admin.managed-dns.update', $domain) }}" method="POST">
    @csrf
    @method('PATCH')
    {{-- Preserved as-is; managed automatically. --}}
    <input type="hidden" name="label_pattern" value="{{ $domain->label_pattern }}">
    <input type="hidden" name="supports_direct_dns" value="1">
    <input type="hidden" name="supports_srv" value="1">

    <div class="row">
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Domain &amp; connection</h3></div>
                <div class="box-body row">
                    <div class="form-group col-md-6"><label>Name</label><input name="name" value="{{ old('name', $domain->name) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Domain</label><input name="domain" value="{{ old('domain', $domain->domain) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Cloudflare zone ID</label><input name="zone_id" value="{{ old('zone_id', $domain->zone_id) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Replace API token</label><input name="api_token" type="password" autocomplete="new-password" class="form-control" placeholder="Leave blank to keep current"></div>
                    <div class="form-group col-xs-12">
                        <input type="hidden" name="enabled" value="0">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="managedDomainEnabled" type="checkbox" name="enabled" value="1" @checked(old('enabled', $domain->enabled))>
                            <label for="managedDomainEnabled" class="strong">Available to customers</label>
                        </div>
                        <p class="help-block">Test the connection below before turning this on.</p>
                    </div>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Who can use it</h3></div>
                <div class="box-body row">
                    <div class="form-group col-md-6"><label>Limit per server</label><input name="per_server_limit" type="number" min="1" value="{{ $domain->per_server_limit }}" class="form-control" placeholder="No limit"></div>
                    <div class="form-group col-md-6"><label>Limit per customer</label><input name="per_user_limit" type="number" min="1" value="{{ $domain->per_user_limit }}" class="form-control" placeholder="No limit"></div>
                    <div class="form-group col-md-6"><label>Allowed nodes</label><select name="allowed_node_ids[]" multiple class="form-control managed-dns-multiselect" data-placeholder="All nodes">
                        @foreach($nodes as $node)<option value="{{ $node->id }}" @selected(in_array($node->id, old('allowed_node_ids', $domain->allowed_node_ids ?? [])))>{{ $node->name }}</option>@endforeach
                    </select></div>
                    <div class="form-group col-md-6"><label>Allowed games</label><select name="allowed_egg_ids[]" multiple class="form-control managed-dns-multiselect" data-placeholder="All games">
                        @foreach($eggs as $egg)<option value="{{ $egg->id }}" @selected(in_array($egg->id, old('allowed_egg_ids', $domain->allowed_egg_ids ?? [])))>{{ $egg->name }}</option>@endforeach
                    </select></div>
                    <div class="form-group col-xs-12"><label>What customers see</label><textarea name="description" class="form-control" rows="2" placeholder="Shown to customers when choosing this domain (optional)">{{ $domain->description }}</textarea></div>
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Save changes</button></div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Connection health</h3></div>
                <div class="box-body">
                    <p>
                        @if($domain->last_provider_status === 'healthy')
                            <span class="label label-success">Working</span>
                        @elseif($domain->last_provider_status)
                            <span class="label label-danger">{{ $domain->last_provider_status }}</span>
                        @else
                            <span class="label label-warning">Not tested yet</span>
                        @endif
                        @if($domain->last_provider_check_at)
                            <span class="text-muted" style="margin-left:8px">{{ $domain->last_provider_check_at->diffForHumans() }}</span>
                        @endif
                    </p>
                    <form action="{{ route('admin.managed-dns.test', $domain) }}" method="POST">@csrf<button class="btn btn-success btn-block">Test connection</button></form>
                </div>
            </div>

            <div class="box box-default collapsed-box">
                <div class="box-header with-border">
                    <h3 class="box-title">Advanced</h3>
                    <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div>
                </div>
                <div class="box-body row" style="display:none">
                    <div class="form-group col-xs-6"><label>DNS TTL (seconds)</label><input name="ttl" type="number" value="{{ old('ttl', $domain->ttl) }}" class="form-control"></div>
                    <div class="form-group col-xs-6"><label>Total limit</label><input name="domain_limit" type="number" min="1" value="{{ $domain->domain_limit }}" class="form-control" placeholder="No limit"></div>
                    <div class="form-group col-xs-12"><label>Reserved names</label><textarea name="reserved_labels" class="form-control" rows="2" placeholder="www, mail, api, admin">{{ implode(', ', $domain->reserved_labels ?? []) }}</textarea></div>
                    <div class="form-group col-xs-12"><label>Fallback IPv4</label><input name="target_ipv4" value="{{ $domain->target_ipv4 }}" class="form-control" placeholder="Used when a node has no public IP"></div>
                    <div class="form-group col-xs-12"><label>Fallback IPv6</label><input name="target_ipv6" value="{{ $domain->target_ipv6 }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Fallback hostname (CNAME)</label><input name="cname_target" value="{{ $domain->cname_target }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Admin notes</label><textarea name="admin_notes" class="form-control" rows="2">{{ $domain->admin_notes }}</textarea></div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(function () {
            $('.managed-dns-multiselect').each(function () {
                $(this).select2({ closeOnSelect: false, placeholder: $(this).data('placeholder'), width: '100%' });
            });
        });
    </script>
@endsection
