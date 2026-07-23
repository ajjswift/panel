<?php

namespace Pterodactyl\Enum;

enum ManagedSubdomainStatus: string
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Active = 'active';
    case Updating = 'updating';
    case Deleting = 'deleting';
    case Failed = 'failed';
    case Conflict = 'conflict';
    case Restricted = 'restricted';
    case OverLimit = 'over_limit';
    case Incompatible = 'incompatible';
    case RepairRequired = 'repair_required';
}
