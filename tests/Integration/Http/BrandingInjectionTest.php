<?php

namespace Pterodactyl\Tests\Integration\Http;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Reseller;

/**
 * Branding must reach the actual rendered page — not just the resolver — for a
 * reseller and its tenants, on the panel's *own* domain as well as on a custom
 * one. These hit the real routes and assert against the HTML.
 */
class BrandingInjectionTest extends HttpTestCase
{
    protected $defaultHeaders = [];

    public function testATenantSeesTheirResellersBrandingOnTheMainDomain(): void
    {
        $reseller = $this->createBrandedReseller();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        $response = $this->actingAs($tenant)->get('/');

        $response->assertOk();
        $this->assertBranded($response->getContent());
    }

    public function testTheResellerItselfSeesItsOwnBrandingOnTheMainDomain(): void
    {
        $reseller = $this->createBrandedReseller();

        $response = $this->actingAs($reseller->owner)->get('/');

        $response->assertOk();
        $this->assertBranded($response->getContent());
    }

    public function testATenantSeesBrandingOnTheAccountPageToo(): void
    {
        $reseller = $this->createBrandedReseller();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        $this->assertBranded($this->actingAs($tenant)->get('/account')->getContent());
    }

    public function testAnUnrelatedUserSeesTheStockPanelOnTheMainDomain(): void
    {
        $this->createBrandedReseller();

        $content = $this->actingAs(User::factory()->create())->get('/')->getContent();

        $this->assertStringNotContainsString('id="reseller-branding"', $content);
        $this->assertStringNotContainsString('Nova Hosting', $content);
    }

    public function testAGuestOnTheMainDomainSeesTheStockPanel(): void
    {
        $this->createBrandedReseller();

        $content = $this->get('/auth/login')->getContent();

        $this->assertStringNotContainsString('id="reseller-branding"', $content);
    }

    /**
     * A disabled reseller's tenants fall back to the stock panel rather than
     * rendering an identity that is no longer active.
     */
    public function testADisabledResellersTenantSeesTheStockPanel(): void
    {
        $reseller = $this->createBrandedReseller(['enabled' => false]);
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        $content = $this->actingAs($tenant)->get('/')->getContent();

        $this->assertStringNotContainsString('id="reseller-branding"', $content);
    }

    private function assertBranded(string $content): void
    {
        $this->assertStringContainsString('id="reseller-branding"', $content, 'The style block should be injected.');
        $this->assertStringContainsString('--brand-500: 34 197 94;', $content, 'The brand ramp should be overridden.');
        $this->assertStringContainsString('--accent-500: 245 158 11;', $content);
        $this->assertStringContainsString('<title>Nova Hosting</title>', $content);
        $this->assertStringContainsString('"name":"Nova Hosting"', $content, 'The SPA should be told the reseller name.');
        $this->assertStringNotContainsString('--gray-', $content, 'The neutral ramp must never be overridden.');

        // Presence alone is not enough, and asserting only that is how this
        // shipped broken once. The bundle uses style-loader, so tailwind.css is
        // injected by JS *after* this block; at equal specificity a plain
        // `:root` here loses to the stock ramp and the page renders unbranded
        // even though every assertion above passes. PHPUnit can't evaluate the
        // cascade, so guard the selector that makes the cascade come out right.
        $this->assertStringContainsString(
            ':root:root {',
            $content,
            'The override must use a doubled :root selector to outrank the runtime-injected theme CSS.'
        );
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
}
