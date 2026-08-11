<?php

namespace Pterodactyl\Providers;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Http\ViewComposers\AssetComposer;
use Pterodactyl\Http\ViewComposers\ResellerComposer;

class ViewComposerServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function boot(): void
    {
        $this->app->make('view')->composer('*', AssetComposer::class);

        // Only the reseller area — ResellerContext throws for anyone who does
        // not own a reseller, so this must never run for other views.
        $this->app->make('view')->composer(
            ['layouts.reseller', 'reseller.*'],
            ResellerComposer::class
        );
    }
}
