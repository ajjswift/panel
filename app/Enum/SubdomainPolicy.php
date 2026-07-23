<?php

namespace Pterodactyl\Enum;

enum SubdomainPolicy: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Inherit = 'inherit';
}
