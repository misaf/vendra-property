<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Misaf\VendraProperty\Support\StorefrontOrigins;
use Misaf\VendraTenant\Models\Tenant;
use Misaf\VendraTenant\Models\TenantDomain;

beforeEach(function (): void {
    config()->set('app.url', 'https://vendra.test');
    config()->set('vendra-tenant.central_host', 'vendra.test');
    StorefrontOrigins::forget();
});

function registerStorefrontDomain(string $domain, bool $active = true): TenantDomain
{
    $tenant = Tenant::factory()->active()->create();

    $tenantDomain = TenantDomain::factory()->for($tenant)->create([
        'name'   => $domain,
        'active' => $active,
    ]);

    forgetCurrentTestTenant();

    return $tenantDomain;
}

it('allows a registered storefront origin to call the canonical API', function (): void {
    registerStorefrontDomain('abbas.example.com');

    $this->withHeaders(['Origin' => 'https://abbas.example.com'])
        ->getJson('https://api.vendra.test/api/catalog/products', [
            'Accept' => 'application/vnd.api+json',
        ])
        ->assertHeader('Access-Control-Allow-Origin', 'https://abbas.example.com');
});

it('allows the www variant of a registered storefront origin', function (): void {
    registerStorefrontDomain('abbas.example.com');

    expect((new StorefrontOrigins())->all())
        ->toContain('https://abbas.example.com')
        ->toContain('https://www.abbas.example.com');
});

it('does not echo an unknown origin', function (): void {
    registerStorefrontDomain('abbas.example.com');

    $this->withHeaders(['Origin' => 'https://attacker.example'])
        ->getJson('https://api.vendra.test/api/catalog/products', [
            'Accept' => 'application/vnd.api+json',
        ])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('never allows a wildcard origin', function (): void {
    registerStorefrontDomain('abbas.example.com');

    expect((new StorefrontOrigins())->all())->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and(config('cors.supports_credentials'))->toBeFalse();
});

it('excludes inactive storefront domains from the allowlist', function (): void {
    registerStorefrontDomain('retired.example.com', active: false);

    expect((new StorefrontOrigins())->all())->not->toContain('https://retired.example.com');
});

it('answers a preflight request for a registered origin without hitting the route', function (): void {
    registerStorefrontDomain('abbas.example.com');

    $response = $this->call('OPTIONS', 'https://api.vendra.test/api/catalog/products', server: [
        'HTTP_ORIGIN'                        => 'https://abbas.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://abbas.example.com');
});

it('does not add CORS headers to panel routes', function (): void {
    registerStorefrontDomain('abbas.example.com');

    $this->withHeaders(['Origin' => 'https://abbas.example.com'])
        ->get('https://console.vendra.test/login')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('forgets the cached allowlist when a domain changes', function (): void {
    $domain = registerStorefrontDomain('abbas.example.com');

    expect((new StorefrontOrigins())->all())->toContain('https://abbas.example.com')
        ->and(Cache::has(StorefrontOrigins::CACHE_KEY))->toBeTrue();

    $domain->update(['active' => false]);

    expect(Cache::has(StorefrontOrigins::CACHE_KEY))->toBeFalse()
        ->and((new StorefrontOrigins())->all())->not->toContain('https://abbas.example.com');
});
