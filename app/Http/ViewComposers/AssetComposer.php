<?php

namespace Pterodactyl\Http\ViewComposers;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Services\Helpers\AssetHashService;
use Pterodactyl\Services\Resellers\ResellerBrandingResolver;

class AssetComposer
{
    /**
     * AssetComposer constructor.
     */
    public function __construct(
        private AssetHashService $assetHashService,
        private ResellerBrandingResolver $branding,
        private Request $request,
    ) {
    }

    /**
     * Provide access to the asset service in the views.
     */
    public function compose(View $view): void
    {
        $branding = $this->branding->resolve($this->request);

        $view->with('asset', $this->assetHashService);
        $view->with('resellerBranding', $branding);
        $view->with('siteConfiguration', [
            // A reseller's users see the reseller's name, not the panel's.
            'name' => $branding->appName ?? config('app.name') ?? 'Pterodactyl',
            'logo' => $branding->logoUrl,
            'locale' => config('app.locale') ?? 'en',
            'recaptcha' => [
                'enabled' => config('recaptcha.enabled', false),
                'siteKey' => config('recaptcha.website_key') ?? '',
            ],
        ]);
    }
}
