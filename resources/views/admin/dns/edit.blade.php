@extends('layouts.admin')

@section('title', 'Managed DNS — ' . $domain->domain)

@section('content-header')
    <h1>{{ $domain->name }}<small>{{ $domain->domain }}</small></h1>
@endsection

@section('content')
<form action="{{ route('admin.managed-dns.update', $domain) }}" method="POST">
    @csrf
    @method('PATCH')
    <div class="row">
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Domain and credentials</h3></div>
                <div class="box-body row">
                    <div class="form-group col-md-6"><label>Display name</label><input name="name" value="{{ old('name', $domain->name) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Root domain</label><input name="domain" value="{{ old('domain', $domain->domain) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Cloudflare zone ID</label><input name="zone_id" value="{{ old('zone_id', $domain->zone_id) }}" class="form-control" required></div>
                    <div class="form-group col-md-6"><label>Replace API token</label><input name="api_token" type="password" autocomplete="new-password" class="form-control" placeholder="Stored securely — leave blank to keep"></div>
                    <div class="form-group col-md-4"><label>TTL</label><input name="ttl" type="number" value="{{ old('ttl', $domain->ttl) }}" class="form-control"></div>
                    <div class="form-group col-md-8"><label>Label pattern</label><input name="label_pattern" value="{{ old('label_pattern', $domain->label_pattern) }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Reserved labels</label><textarea name="reserved_labels" class="form-control">{{ implode(', ', $domain->reserved_labels ?? []) }}</textarea></div>
                    <div class="form-group col-xs-12">
                        <input type="hidden" name="enabled" value="0">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="managedDomainEnabled" type="checkbox" name="enabled" value="1" @checked(old('enabled', $domain->enabled))>
                            <label for="managedDomainEnabled" class="strong">Enabled for eligible servers</label>
                        </div>
                    </div>
                    <div class="form-group col-xs-6">
                        <input type="hidden" name="supports_direct_dns" value="0">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="managedDomainSupportsDirectDns" type="checkbox" name="supports_direct_dns" value="1" @checked(old('supports_direct_dns', $domain->supports_direct_dns))>
                            <label for="managedDomainSupportsDirectDns" class="strong">Direct DNS</label>
                        </div>
                    </div>
                    <div class="form-group col-xs-6">
                        <input type="hidden" name="supports_srv" value="0">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="managedDomainSupportsSrv" type="checkbox" name="supports_srv" value="1" @checked(old('supports_srv', $domain->supports_srv))>
                            <label for="managedDomainSupportsSrv" class="strong">SRV records</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Limits and safe fallback targets</h3></div>
                <div class="box-body row">
                    <div class="form-group col-xs-4"><label>Per server</label><input name="per_server_limit" type="number" value="{{ $domain->per_server_limit }}" class="form-control"></div>
                    <div class="form-group col-xs-4"><label>Per user</label><input name="per_user_limit" type="number" value="{{ $domain->per_user_limit }}" class="form-control"></div>
                    <div class="form-group col-xs-4"><label>Domain total</label><input name="domain_limit" type="number" value="{{ $domain->domain_limit }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Public IPv4 fallback</label><input name="target_ipv4" value="{{ $domain->target_ipv4 }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Public IPv6 fallback</label><input name="target_ipv6" value="{{ $domain->target_ipv6 }}" class="form-control"></div>
                    <div class="form-group col-xs-12"><label>Approved CNAME fallback</label><input name="cname_target" value="{{ $domain->cname_target }}" class="form-control"></div>
                </div>
            </div>
        </div>
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Eligibility</h3></div>
                <div class="box-body row">
                    <div class="form-group col-md-4"><label>Allowed nodes (empty means all)</label><select id="allowedNodeIds" name="allowed_node_ids[]" multiple class="form-control managed-dns-multiselect" data-placeholder="All nodes">
                        @foreach($nodes as $node)<option value="{{ $node->id }}" @selected(in_array($node->id, old('allowed_node_ids', $domain->allowed_node_ids ?? [])))>{{ $node->name }}</option>@endforeach
                    </select></div>
                    <div class="form-group col-md-4"><label>Allowed eggs (empty means all)</label><select id="allowedEggIds" name="allowed_egg_ids[]" multiple class="form-control managed-dns-multiselect" data-placeholder="All eggs">
                        @foreach($eggs as $egg)<option value="{{ $egg->id }}" @selected(in_array($egg->id, old('allowed_egg_ids', $domain->allowed_egg_ids ?? [])))>{{ $egg->name }}</option>@endforeach
                    </select></div>
                    <div class="form-group col-md-4"><label>Allowed service profiles</label><select id="allowedServiceProfileIds" name="allowed_service_profile_ids[]" multiple class="form-control managed-dns-multiselect" data-placeholder="All service profiles">
                        @foreach($profiles as $profile)<option value="{{ $profile->id }}" @selected(in_array($profile->id, old('allowed_service_profile_ids', $domain->allowed_service_profile_ids ?? [])))>{{ $profile->name }}</option>@endforeach
                    </select></div>
                    <div class="form-group col-md-6"><label>User-facing description</label><textarea name="description" class="form-control">{{ $domain->description }}</textarea></div>
                    <div class="form-group col-md-6"><label>Administrator notes</label><textarea name="admin_notes" class="form-control">{{ $domain->admin_notes }}</textarea></div>
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Save domain configuration</button></div>
            </div>
        </div>
    </div>
</form>

<div class="row">
    <div class="col-xs-12">
        <form action="{{ route('admin.managed-dns.test', $domain) }}" method="POST" style="display:inline">@csrf<button class="btn btn-success">Test Cloudflare access</button></form>
        <span class="text-muted" style="margin-left:10px">Last result: {{ $domain->last_provider_status ?? 'not tested' }}</span>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(function () {
            $('.managed-dns-multiselect').each(function () {
                $(this).select2({
                    closeOnSelect: false,
                    placeholder: $(this).data('placeholder'),
                    width: '100%'
                });
            });
        });
    </script>
@endsection
