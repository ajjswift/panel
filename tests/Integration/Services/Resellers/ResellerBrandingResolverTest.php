<?php

namespace Pterodactyl\Tests\Integration\Services\Resellers;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Models\ResellerDomain;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Resellers\ResellerBrandingResolver;

class ResellerBrandingResolverTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // The resolver memoises per instance and caches host lookups; tests
        // build their own instance and flush between cases.
        $this->app->make('cache')->clear();
    }

    public function testStockThemeWhenNothingResolves(): void
    {
        $branding = $this->resolver()->resolve($this->request('panel.test'));

        $this->assertTrue($branding->isEmpty());
        $this->assertNull($branding->appName);
        $this->assertSame('', $branding->cssVariables());
    }

    public function testAVerifiedHostResolvesForAGuest(): void
    {
        $reseller = $this->createBrandedReseller();
        $host = $this->createDomain($reseller, verified: true)->hostname;

        $branding = $this->resolver()->resolve($this->request($host));

        $this->assertSame($reseller->id, $branding->resellerId);
        $this->assertSame('Nova Hosting', $branding->appName);
        $this->assertStringContainsString('--brand-500: 34 197 94;', $branding->cssVariables());
    }

    public function testHostMatchingIsCaseInsensitive(): void
    {
        $reseller = $this->createBrandedReseller();
        $host = $this->createDomain($reseller, verified: true)->hostname;

        $branding = $this->resolver()->resolve($this->request(mb_strtoupper($host)));

        $this->assertSame($reseller->id, $branding->resellerId);
    }

    /**
     * The whole point of the verification step: an unverified hostname must not
     * be able to claim an identity.
     */
    public function testAnUnverifiedHostIsIgnored(): void
    {
        $reseller = $this->createBrandedReseller();
        $host = $this->createDomain($reseller, verified: false)->hostname;

        $branding = $this->resolver()->resolve($this->request($host));

        $this->assertTrue($branding->isEmpty());
    }

    public function testAResellerSeesItsOwnBranding(): void
    {
        $reseller = $this->createBrandedReseller();

        $branding = $this->resolver()->resolve($this->request('panel.test', $reseller->owner));

        $this->assertSame($reseller->id, $branding->resellerId);
    }

    public function testATenantSeesItsResellersBranding(): void
    {
        $reseller = $this->createBrandedReseller();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        $branding = $this->resolver()->resolve($this->request('panel.test', $tenant));

        $this->assertSame($reseller->id, $branding->resellerId);
        $this->assertSame('Nova Hosting', $branding->appName);
    }

    public function testAnUnrelatedUserSeesTheStockTheme(): void
    {
        $this->createBrandedReseller();

        $branding = $this->resolver()->resolve($this->request('panel.test', User::factory()->create()));

        $this->assertTrue($branding->isEmpty());
    }

    public function testADisabledResellerFallsBackToTheStockTheme(): void
    {
        $reseller = $this->createBrandedReseller(['enabled' => false]);
        $host = $this->createDomain($reseller, verified: true)->hostname;

        $this->assertTrue($this->resolver()->resolve($this->request($host))->isEmpty());
    }

    /**
     * The host is checked before the session so a reseller's own domain brands
     * the login page, and so an unrelated visitor there sees that reseller's
     * identity rather than their own.
     */
    public function testTheHostWinsOverTheAuthenticatedUser(): void
    {
        $hostReseller = $this->createBrandedReseller(['app_name' => 'Host Brand']);
        $host = $this->createDomain($hostReseller, verified: true)->hostname;

        $otherReseller = $this->createBrandedReseller(['app_name' => 'Other Brand']);

        $branding = $this->resolver()->resolve($this->request($host, $otherReseller->owner));

        $this->assertSame('Host Brand', $branding->appName);
    }

    public function testCssVariablesOnlyTouchTheBrandAndAccentRamps(): void
    {
        $reseller = $this->createBrandedReseller();

        $css = $this->resolver()->resolve($this->request('panel.test', $reseller->owner))->cssVariables();

        $this->assertStringNotContainsString('--gray-', $css, 'The neutral ramp must never be overridden.');
        $this->assertStringNotContainsString('--color-', $css);
        $this->assertMatchesRegularExpression('/--brand-50: \d+ \d+ \d+;/', $css);
    }

    private function resolver(): ResellerBrandingResolver
    {
        // A fresh instance each call: the resolver memoises its answer for the
        // life of a request.
        return new ResellerBrandingResolver(
            $this->app->make('cache.store'),
            $this->app->make(\Pterodactyl\Services\Resellers\ColorRampGenerator::class),
        );
    }

    private function request(string $host, ?User $user = null): Request
    {
        $request = Request::create('https://' . $host . '/');

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }

    private function createBrandedReseller(array $attributes = []): Reseller
    {
        return Reseller::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'app_name' => 'Nova Hosting',
            'brand_color' => '#22c55e',
            'accent_color' => '#f59e0b',
        ], $attributes))->load('owner');
    }

    /**
     * The integration suite shares one database and does not roll back between
     * tests, so hostnames — which are globally unique — must be per-test.
     */
    private function createDomain(Reseller $reseller, bool $verified, ?string $hostname = null): ResellerDomain
    {
        $domain = new ResellerDomain();
        $domain->forceFill([
            'reseller_id' => $reseller->id,
            'hostname' => $hostname ?? strtolower(\Illuminate\Support\Str::random(12)) . '.example.com',
            'verification_token' => 'solstice-verify-test',
            'verified_at' => $verified ? now() : null,
        ])->save();

        return $domain;
    }
}
