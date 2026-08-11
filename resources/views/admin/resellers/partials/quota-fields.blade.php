{{--
    Shared quota inputs for the admin create/edit reseller forms.
    Expects $reseller (may be null when creating).
--}}
<div class="box-body row">
    <div class="col-xs-12">
        <p class="text-muted">
            Across every field: <code>-1</code> means unlimited and <code>0</code> means none.
            Memory and disk are in MiB; CPU is in percent, where 100 is one full thread.
        </p>
    </div>
    @foreach ([
        'memory' => 'Memory (MiB)',
        'disk' => 'Disk (MiB)',
        'cpu' => 'CPU (%)',
        'server_limit' => 'Servers',
        'user_limit' => 'Users',
        'database_limit' => 'Databases',
        'allocation_limit' => 'Additional ports',
        'backup_limit' => 'Backups',
    ] as $field => $label)
        <div class="form-group col-sm-3">
            <label class="control-label">{{ $label }}</label>
            <input type="number" min="-1" name="{{ $field }}" value="{{ old($field, $reseller->{$field} ?? 0) }}" class="form-control" />
        </div>
    @endforeach
</div>
