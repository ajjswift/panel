<?php

namespace Pterodactyl\Enum;

enum DnsRoutingMode: string
{
    case DirectDns = 'direct_dns';
    case ReverseProxy = 'reverse_proxy';
}
