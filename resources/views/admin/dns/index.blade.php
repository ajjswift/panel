@extends('layouts.admin')

@section('title', 'Domains')

@section('content-header')
    <h1>Domains<small>Let customers give their servers friendly addresses.</small></h1>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Connected domains</h3></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <tr><th>Domain</th><th>Status</th><th>Addresses in use</th><th></th></tr>
                    @forelse($domains as $domain)
                        <tr>
                            <td><strong>{{ $domain->name }}</strong><br><code>{{ $domain->domain }}</code></td>
                            <td>
                                @if(!$domain->enabled)
                                    <span class="label label-default">Off</span>
                                @elseif($domain->last_provider_status === 'healthy')
                                    <span class="label label-success">Working</span>
                                @elseif($domain->last_provider_status)
                                    <span class="label label-danger">Needs attention</span>
                                @else
                                    <span class="label label-warning">Not tested</span>
                                @endif
                            </td>
                            <td>{{ $domain->managed_subdomains_count }}</td>
                            <td class="text-right"><a class="btn btn-xs btn-primary" href="{{ route('admin.managed-dns.edit', $domain) }}">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No domains connected yet. Add one below.</td></tr>
                    @endforelse
                </table>
            </div>
            @if($domains->hasPages())
                <div class="box-footer text-center">{{ $domains->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-8 col-md-offset-2">
        <form id="managedDomainCreateForm" action="{{ route('admin.managed-dns.store') }}" method="POST">
            @csrf
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Add a domain</h3></div>
                <div class="box-body">
                    <p class="text-muted" style="margin-top:-4px">
                        Connect a domain you own in Cloudflare. Customers can then create addresses like
                        <code>play.yourdomain.com</code> for their servers.
                    </p>
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label>Name</label>
                            <input name="name" class="form-control" placeholder="e.g. Community Domains" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Domain</label>
                            <input id="managedDomainName" name="domain" class="form-control" placeholder="yourdomain.com" required>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Cloudflare API token</label>
                            <input id="managedDomainApiToken" name="api_token" type="password" autocomplete="new-password" class="form-control" placeholder="Token with DNS edit access" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>&nbsp;</label>
                            <button id="discoverCloudflareZone" type="button" class="btn btn-default btn-block">
                                <i class="fa fa-cloud"></i> Connect
                            </button>
                        </div>
                        <div class="col-xs-12">
                            <p id="cloudflareDiscoveryStatus" class="help-block">
                                The token needs DNS edit access for this domain. We check the connection without changing your site or nameservers.
                            </p>
                        </div>
                    </div>

                    <input type="hidden" id="managedDomainZoneId" name="zone_id" value="">

                    <div class="form-group" id="enableRow" style="display:none">
                        <div class="checkbox checkbox-primary no-margin-bottom">
                            <input id="managedDomainEnabled" type="checkbox" name="enabled" value="1">
                            <label for="managedDomainEnabled" class="strong">Make available to customers right away</label>
                        </div>
                    </div>
                    <p>
                        <a href="#" id="manualZoneToggle" class="text-muted small">Enter zone ID manually instead</a>
                    </p>
                    <div class="form-group" id="manualZoneRow" style="display:none">
                        <label>Cloudflare zone ID</label>
                        <input type="text" class="form-control" oninput="document.getElementById('managedDomainZoneId').value = this.value">
                    </div>
                </div>
                <div class="box-footer"><button class="btn btn-primary pull-right">Save</button></div>
            </div>
        </form>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(function () {
            var $button = $('#discoverCloudflareZone');
            var $status = $('#cloudflareDiscoveryStatus');

            $('#manualZoneToggle').on('click', function (e) {
                e.preventDefault();
                $('#manualZoneRow, #enableRow').show();
                $(this).hide();
            });

            $button.on('click', function () {
                var domain = $.trim($('#managedDomainName').val());
                var token = $('#managedDomainApiToken').val();

                if (!domain || !token) {
                    $status.removeClass('text-success').addClass('text-danger').text('Enter the domain and Cloudflare API token first.');
                    return;
                }

                $button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Connecting...');
                $status.removeClass('text-success text-danger').text('Checking the connection to Cloudflare...');

                $.ajax({
                    method: 'POST',
                    url: '{{ route('admin.managed-dns.discover') }}',
                    data: { _token: '{{ csrf_token() }}', domain: domain, api_token: token }
                }).done(function (response) {
                    $('#managedDomainZoneId').val(response.zone_id);
                    $('#enableRow').show();
                    $status.removeClass('text-danger').addClass('text-success').text('Connected to ' + response.zone_name + '. You can save now.');
                }).fail(function (jqXHR) {
                    var message = 'We could not connect to Cloudflare with those details.';
                    if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    } else if (jqXHR.responseJSON && jqXHR.responseJSON.errors && jqXHR.responseJSON.errors[0]) {
                        message = jqXHR.responseJSON.errors[0].detail;
                    }
                    $status.removeClass('text-success').addClass('text-danger').text(message);
                }).always(function () {
                    $button.prop('disabled', false).html('<i class="fa fa-cloud"></i> Connect');
                });
            });
        });
    </script>
@endsection
