<?php

namespace Pterodactyl\Enum;

enum SubdomainCompatibility: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
}
