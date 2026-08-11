<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Reseller;

/*
| The reseller control panel. Deny-by-default: nothing here is reachable
| without ResellerAuthenticate (see RouteServiceProvider), and every controller
| resolves its models through ResellerContext rather than route-model binding
| so a foreign id 404s instead of loading.
*/

Route::get('/', [Reseller\BaseController::class, 'index'])->name('reseller.index');

Route::group(['prefix' => 'users'], function () {
    Route::get('/', [Reseller\UserController::class, 'index'])->name('reseller.users');
    Route::get('/new', [Reseller\UserController::class, 'create'])->name('reseller.users.new');
    Route::get('/view/{user}', [Reseller\UserController::class, 'view'])->name('reseller.users.view');

    Route::post('/new', [Reseller\UserController::class, 'store']);
    Route::patch('/view/{user}', [Reseller\UserController::class, 'update']);
    Route::delete('/view/{user}', [Reseller\UserController::class, 'delete']);
});

Route::group(['prefix' => 'servers'], function () {
    Route::get('/', [Reseller\ServerController::class, 'index'])->name('reseller.servers');
    Route::get('/new', [Reseller\ServerController::class, 'create'])->name('reseller.servers.new');
    Route::get('/view/{server}', [Reseller\ServerController::class, 'view'])->name('reseller.servers.view');

    Route::post('/new', [Reseller\ServerController::class, 'store']);
    Route::patch('/view/{server}/details', [Reseller\ServerController::class, 'updateDetails'])->name('reseller.servers.details');
    Route::patch('/view/{server}/build', [Reseller\ServerController::class, 'updateBuild'])->name('reseller.servers.build');
    Route::post('/view/{server}/suspension', [Reseller\ServerController::class, 'toggleSuspension'])->name('reseller.servers.suspension');
    Route::delete('/view/{server}', [Reseller\ServerController::class, 'delete']);
});

Route::group(['prefix' => 'branding'], function () {
    Route::get('/', [Reseller\BrandingController::class, 'index'])->name('reseller.branding');
    Route::patch('/', [Reseller\BrandingController::class, 'update']);

    Route::post('/domains', [Reseller\BrandingController::class, 'storeDomain'])->name('reseller.branding.domains.store');
    Route::post('/domains/{domain}/verify', [Reseller\BrandingController::class, 'verifyDomain'])->name('reseller.branding.domains.verify');
    Route::delete('/domains/{domain}', [Reseller\BrandingController::class, 'deleteDomain'])->name('reseller.branding.domains.delete');
});
