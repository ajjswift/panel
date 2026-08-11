<?php

namespace Pterodactyl\Http\ViewComposers;

use Illuminate\View\View;
use Pterodactyl\Services\Resellers\ResellerContext;
use Pterodactyl\Services\Resellers\ResellerQuotaService;

/**
 * Shares the acting reseller (and its current usage) with every view in the
 * /reseller area, so the layout chrome and dashboard don't each have to fetch
 * them.
 */
class ResellerComposer
{
    public function __construct(
        private ResellerContext $context,
        private ResellerQuotaService $quota,
    ) {
    }

    public function compose(View $view): void
    {
        $reseller = $this->context->reseller();

        $view->with('reseller', $reseller);
        $view->with('usage', $this->quota->usage($reseller));
    }
}
