<?php

namespace Pterodactyl\Http\Controllers\Reseller;

use Illuminate\View\View;
use Pterodactyl\Models\Server;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Resellers\ResellerContext;

class BaseController extends Controller
{
    public function __construct(private ResellerContext $context)
    {
    }

    /**
     * The reseller dashboard: quota consumption plus a quick look at what is
     * currently running. `$reseller` and `$usage` come from ResellerComposer.
     */
    public function index(): View
    {
        return view('reseller.index', [
            'servers' => $this->context->servers()->count(),
            'suspended' => $this->context->servers()->where('status', Server::STATUS_SUSPENDED)->count(),
            'users' => $this->context->users()->count(),
            'nodes' => $this->context->nodes()->get(),
        ]);
    }
}
