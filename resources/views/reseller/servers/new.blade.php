{{--
    Reseller server creation. Structurally the admin new-server form with the
    admin-only controls (CPU pinning, OOM killer, install-script skipping, DNS
    policy, external id) removed, and the node/owner selects limited to what the
    reseller has been granted. The JS is the stock js/admin/new-server.js, which
    is driven entirely by the JavaScript::put payload the controller scopes.
--}}
@extends('layouts.reseller')

@section('title')
    New Server
@endsection

@section('content-header')
    <h1>Create Server<small>Provision a server for one of your users.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('reseller.index') }}">Reseller</a></li>
        <li><a href="{{ route('reseller.servers') }}">Servers</a></li>
        <li class="active">Create Server</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('reseller.servers.new') }}" method="POST">
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Core Details</h3>
                </div>
                <div class="box-body row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="pName">Server Name</label>
                            <input type="text" class="form-control" id="pName" name="name" value="{{ old('name') }}" placeholder="Server Name">
                            <p class="small text-muted no-margin">Character limits: <code>a-z A-Z 0-9 _ - .</code> and <code>[Space]</code>.</p>
                        </div>
                        <div class="form-group">
                            <label for="pUserId">Server Owner</label>
                            <select id="pUserId" name="owner_id" class="form-control">
                                @foreach($owners as $owner)
                                    <option value="{{ $owner->id }}" @selected((int) old('owner_id') === $owner->id)>{{ $owner->email }} ({{ $owner->username }})</option>
                                @endforeach
                            </select>
                            <p class="small text-muted no-margin">Only accounts you manage can be selected.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="pDescription" class="control-label">Server Description</label>
                            <textarea id="pDescription" name="description" rows="3" class="form-control">{{ old('description') }}</textarea>
                        </div>
                        <div class="form-group">
                            <div class="checkbox checkbox-primary no-margin-bottom">
                                {{-- value="1" is required: a checkbox without it submits the
                                     string "on", which fails the `boolean` rule. The admin
                                     form omits it only because it never validates this field. --}}
                                <input id="pStartOnCreation" name="start_on_completion" type="checkbox" value="1" {{ \Pterodactyl\Helpers\Utilities::checked('start_on_completion', 1) }} />
                                <label for="pStartOnCreation" class="strong">Start Server when Installed</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Allocation Management</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-sm-4">
                        <label for="pNodeId">Node</label>
                        <select name="node_id" id="pNodeId" class="form-control">
                            @foreach($nodes as $node)
                                <option value="{{ $node->id }}" @selected((int) old('node_id') === $node->id)>{{ $node->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-4">
                        <label for="pAllocation">Default Allocation</label>
                        <select id="pAllocation" name="allocation_id" class="form-control"></select>
                    </div>
                    <div class="form-group col-sm-4">
                        <label for="pAllocationAdditional">Additional Allocation(s)</label>
                        <select id="pAllocationAdditional" name="allocation_additional[]" class="form-control" multiple></select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Resource Management</h3>
                    <div class="pull-right text-muted small">
                        Memory remaining: <strong>{{ $usage->isUnlimited('memory') ? 'unlimited' : $usage->remaining('memory') . ' MiB' }}</strong> &middot;
                        Disk remaining: <strong>{{ $usage->isUnlimited('disk') ? 'unlimited' : $usage->remaining('disk') . ' MiB' }}</strong>
                    </div>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-6">
                        <label for="pMemory">Memory</label>
                        <div class="input-group">
                            <input type="text" id="pMemory" name="memory" class="form-control" value="{{ old('memory') }}" />
                            <span class="input-group-addon">MiB</span>
                        </div>
                    </div>
                    <div class="form-group col-xs-6">
                        <label for="pSwap">Swap</label>
                        <div class="input-group">
                            <input type="text" id="pSwap" name="swap" class="form-control" value="{{ old('swap', 0) }}" />
                            <span class="input-group-addon">MiB</span>
                        </div>
                    </div>
                    <div class="form-group col-xs-6">
                        <label for="pDisk">Disk Space</label>
                        <div class="input-group">
                            <input type="text" id="pDisk" name="disk" class="form-control" value="{{ old('disk') }}" />
                            <span class="input-group-addon">MiB</span>
                        </div>
                    </div>
                    <div class="form-group col-xs-6">
                        <label for="pCPU">CPU Limit</label>
                        <div class="input-group">
                            <input type="text" id="pCPU" name="cpu" class="form-control" value="{{ old('cpu', 0) }}" />
                            <span class="input-group-addon">%</span>
                        </div>
                        <p class="text-muted small">100 = one full thread. <code>0</code> is unlimited.</p>
                    </div>
                    <div class="form-group col-xs-6">
                        <label for="pIO">Block IO Weight</label>
                        <input type="text" id="pIO" name="io" class="form-control" value="{{ old('io', 500) }}" />
                        <p class="text-muted small">Between <code>10</code> and <code>1000</code>. Leave at 500 unless you know otherwise.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Feature Limits</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-4">
                        <label for="pDatabaseLimit" class="control-label">Database Limit</label>
                        <input type="text" id="pDatabaseLimit" name="database_limit" class="form-control" value="{{ old('database_limit', 0) }}"/>
                    </div>
                    <div class="form-group col-xs-4">
                        <label for="pAllocationLimit" class="control-label">Allocation Limit</label>
                        <input type="text" id="pAllocationLimit" name="allocation_limit" class="form-control" value="{{ old('allocation_limit', 0) }}"/>
                    </div>
                    <div class="form-group col-xs-4">
                        <label for="pBackupLimit" class="control-label">Backup Limit</label>
                        <input type="text" id="pBackupLimit" name="backup_limit" class="form-control" value="{{ old('backup_limit', 0) }}"/>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Game Configuration</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-12">
                        <label for="pNestId">Nest</label>
                        <select id="pNestId" name="nest_id" class="form-control">
                            @foreach($nests as $nest)
                                <option value="{{ $nest->id }}" @selected((int) old('nest_id') === $nest->id)>{{ $nest->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-xs-12">
                        <label for="pEggId">Egg</label>
                        <select id="pEggId" name="egg_id" class="form-control"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Docker Configuration</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-12">
                        <label for="pDefaultContainer">Docker Image</label>
                        <select id="pDefaultContainer" name="image" class="form-control"></select>
                        <input id="pDefaultContainerCustom" name="custom_image" value="{{ old('custom_image') }}" class="form-control" placeholder="Or enter a custom image..." style="margin-top:1rem"/>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Startup Configuration</h3>
                </div>
                <div class="box-body row">
                    <div class="form-group col-xs-12">
                        <label for="pStartup">Startup Command</label>
                        <input type="text" id="pStartup" name="startup" value="{{ old('startup') }}" class="form-control" />
                    </div>
                </div>
                <div class="box-header with-border" style="margin-top:-10px;">
                    <h3 class="box-title">Service Variables</h3>
                </div>
                <div class="box-body row" id="appendVariablesTo"></div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <input type="submit" class="btn btn-success pull-right" value="Create Server" />
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@section('footer-scripts')
    @parent
    {!! Theme::js('vendor/lodash/lodash.js') !!}

    <script type="application/javascript">
        // Persist 'Service Variables'
        function serviceVariablesUpdated(eggId, ids) {
            @if (old('egg_id'))
                if (eggId != '{{ old('egg_id') }}') {
                    return;
                }

                @if (old('environment'))
                    @foreach (old('environment') as $key => $value)
                        $('#' + ids['{{ $key }}']).val('{{ $value }}');
                    @endforeach
                @endif
            @endif
            @if(old('image'))
                $('#pDefaultContainer').val('{{ old('image') }}');
            @endif
        }
    </script>

    {!! Theme::js('js/admin/new-server.js?v=20220530') !!}

    <script type="application/javascript">
        $(document).ready(function() {
            @if (old('node_id'))
                $('#pNodeId').val('{{ old('node_id') }}').change();

                @if (old('allocation_id'))
                    $('#pAllocation').val('{{ old('allocation_id') }}').change();
                @endif

                @if (old('allocation_additional'))
                    const additional_allocations = [];

                    @for ($i = 0; $i < count(old('allocation_additional')); $i++)
                        additional_allocations.push('{{ old('allocation_additional.'.$i) }}');
                    @endfor

                    $('#pAllocationAdditional').val(additional_allocations).change();
                @endif
            @endif

            @if (old('nest_id'))
                $('#pNestId').val('{{ old('nest_id') }}').change();

                @if (old('egg_id'))
                    $('#pEggId').val('{{ old('egg_id') }}').change();
                @endif
            @endif
        });
    </script>
@endsection
