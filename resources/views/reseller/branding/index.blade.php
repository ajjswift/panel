@extends('layouts.reseller')

@section('title')
    Branding
@endsection

@section('content-header')
    <h1>Branding<small>How the panel looks to your users.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('reseller.index') }}">Reseller</a></li>
        <li class="active">Branding</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <form action="{{ route('reseller.branding') }}" method="POST" enctype="multipart/form-data">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Identity</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">Panel name</label>
                        <input type="text" name="app_name" value="{{ old('app_name', $reseller->app_name) }}" class="form-control" placeholder="{{ config('app.name') }}" />
                        <p class="text-muted small">Shown in the browser tab and throughout the client area for your users.</p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Logo</label>
                        @if($reseller->logo_path)
                            <p><img src="{{ Storage::disk('public')->url($reseller->logo_path) }}" alt="Current logo" style="max-height:48px;background:#222;padding:6px;border-radius:4px;" /></p>
                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" id="removeLogo" name="remove_logo" value="1" />
                                <label for="removeLogo">Remove current logo</label>
                            </div>
                        @endif
                        <input type="file" name="logo" class="form-control" accept="image/*" />
                        <p class="text-muted small">PNG, JPG, SVG or WebP, up to 512 KB.</p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Favicon</label>
                        @if($reseller->favicon_path)
                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" id="removeFavicon" name="remove_favicon" value="1" />
                                <label for="removeFavicon">Remove current favicon</label>
                            </div>
                        @endif
                        <input type="file" name="favicon" class="form-control" accept="image/*" />
                        <p class="text-muted small">PNG, ICO or SVG, up to 128 KB.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Colours</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">Brand colour</label>
                        <input type="color" name="brand_color" id="brandColor" value="{{ old('brand_color', $reseller->brand_color ?: '#a855f7') }}" class="form-control" style="height:40px;padding:4px;" />
                        <p class="text-muted small">Buttons, links and highlights.</p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Accent colour</label>
                        <input type="color" name="accent_color" id="accentColor" value="{{ old('accent_color', $reseller->accent_color ?: '#ff8a3d') }}" class="form-control" style="height:40px;padding:4px;" />
                        <p class="text-muted small">Secondary indicators and charts.</p>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Generated shades</label>
                        <div id="brandPreview" style="display:flex;border-radius:4px;overflow:hidden;height:28px;margin-bottom:6px;">
                            @foreach($preview['brand'] as $stop => $triplet)
                                <div style="flex:1;background:rgb({{ $triplet }});" title="brand-{{ $stop }}"></div>
                            @endforeach
                        </div>
                        <div id="accentPreview" style="display:flex;border-radius:4px;overflow:hidden;height:28px;">
                            @foreach($preview['accent'] as $stop => $triplet)
                                <div style="flex:1;background:rgb({{ $triplet }});" title="accent-{{ $stop }}"></div>
                            @endforeach
                        </div>
                        <p class="text-muted small">The full 50–900 ramp is generated from your two colours. Save to refresh this preview.</p>
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    {!! method_field('PATCH') !!}
                    <input type="submit" class="btn btn-primary btn-sm" value="Save Branding" />
                </div>
            </div>
        </div>
    </form>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Your domains</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">
                    Point a hostname you own at this panel and your branding will be used for anyone visiting it —
                    including the login page, before they sign in. Verify ownership by publishing the TXT record shown
                    below. Setting up DNS and the TLS certificate for the hostname is handled outside the panel;
                    ask the panel administrator if you're not sure.
                </p>
            </div>
            <div class="box-body table-responsive no-padding">
                @if($domains->isEmpty())
                    <p style="padding:0 15px 15px;" class="text-muted no-margin">No domains added yet.</p>
                @else
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Hostname</th><th>Status</th><th>TXT record to publish</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($domains as $domain)
                                <tr>
                                    <td>{{ $domain->hostname }}</td>
                                    <td>
                                        @if($domain->isVerified())
                                            <span class="label label-success">Verified</span>
                                        @else
                                            <span class="label label-warning">Pending</span>
                                            @if($domain->last_check_error)
                                                <p class="text-muted small no-margin">{{ $domain->last_check_error }}</p>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        @if(!$domain->isVerified())
                                            <code>{{ $domain->verificationRecordName() }}</code><br>
                                            <code>{{ $domain->verification_token }}</code>
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if(!$domain->isVerified())
                                            <form action="{{ route('reseller.branding.domains.verify', $domain->id) }}" method="POST" style="display:inline;">
                                                {!! csrf_field() !!}
                                                <button type="submit" class="btn btn-xs btn-primary">Verify</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('reseller.branding.domains.delete', $domain->id) }}" method="POST" style="display:inline;">
                                            {!! csrf_field() !!}
                                            {!! method_field('DELETE') !!}
                                            <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="box-footer">
                <form action="{{ route('reseller.branding.domains.store') }}" method="POST">
                    {!! csrf_field() !!}
                    <div class="input-group" style="max-width:420px;">
                        <input type="text" name="hostname" class="form-control" placeholder="panel.example.com" value="{{ old('hostname') }}" />
                        <span class="input-group-btn">
                            <button type="submit" class="btn btn-primary">Add domain</button>
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
