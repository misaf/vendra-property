<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Jobs\ProvisionStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Services\ContainerStorefrontProvisioner;
use Misaf\VendraProperty\Support\StorefrontProvisionRequest;

/**
 * @param array<string, mixed> $overrides
 */
function storefrontConfiguration(array $overrides = []): array
{
    return [
        'slug'          => 'acme-flowers',
        'theme'         => 'default',
        'domain'        => 'acme.test',
        'siteUrl'       => 'https://acme.test',
        'businessType'  => 'Florist',
        'priceCurrency' => 'IRR',
        'name'          => ['en' => 'Acme Flowers'],
        'address'       => ['locality' => 'Tehran', 'country' => 'IR'],
        'contact'       => [
            'mobilePhone' => '09120000000',
            'officePhone' => '02100000000',
            'email'       => 'contact@acme.test',
            'hoursOpen'   => '08:00',
            'hoursClose'  => '21:00',
            'mapQuery'    => '35.7,51.4',
        ],
        'social' => [
            'whatsappPhone'     => '+989120000000',
            'telegramUsername'  => 'acmeflowers',
            'instagramUsername' => 'acmeflowers',
        ],
        ...$overrides,
    ];
}

/**
 * @param array<string, mixed> $overrides
 */
function storefrontRequest(array $overrides = []): StorefrontProvisionRequest
{
    return new StorefrontProvisionRequest(
        tenantId: 1,
        slug: $overrides['slug'] ?? 'acme-flowers',
        domain: $overrides['domain'] ?? 'acme.test',
        theme: $overrides['theme'] ?? 'default',
        image: $overrides['image'] ?? 'ghcr.io/misaf/vendra-storefront-florist@sha256:abc123',
        configuration: $overrides['configuration'] ?? storefrontConfiguration(),
    );
}

/**
 * Run the job the way the queue does: dependencies resolved from the container.
 */
function runProvisionJob(StorefrontDeployment $deployment, bool $force = false): void
{
    app()->call([new ProvisionStorefrontJob($deployment->id, $force), 'handle']);
}

beforeEach(function (): void {
    Config::set('vendra-container.endpoint', 'http://provisioner:8080');
    Config::set('vendra-property.storefront.image', 'ghcr.io/misaf/vendra-storefront-florist@sha256:abc123');
    Config::set('vendra-property.storefront.themes', ['default']);
    Config::set('vendra-property.storefront.network', 'traefik-public');
    Config::set('vendra-property.storefront.base_domain', 'vendra.test');
    Config::set('vendra-property.storefront.cert_resolver', 'letsencrypt');
    Config::set('vendra-property.storefront.certificates_path', '/var/lib/vendra/certificates');
    Config::set('vendra-property.storefront.ca_file', 'vendra-ca.crt');
});

it('resolves the docker provisioner', function (): void {
    expect(app(StorefrontProvisioner::class))->toBeInstanceOf(ContainerStorefrontProvisioner::class);
});

it('creates and starts a storefront container and reports it ready', function (): void {
    fakeDockerEngine();

    $result = app(StorefrontProvisioner::class)->provision(storefrontRequest());

    expect($result->ready)->toBeTrue()
        ->and($result->reference)->toBe('vendra-storefront-acme-flowers')
        ->and($result->imageDigest)->toBe('sha256:abc123');

    Http::assertSent(fn(Request $request): bool => 'POST' === $request->method()
        && Str::contains($request->url(), '/containers/create')
        && Str::contains($request->url(), 'name=vendra-storefront-acme-flowers'));

    Http::assertSent(fn(Request $request): bool => 'POST' === $request->method()
        && Str::contains($request->url(), '/containers/vendra-storefront-acme-flowers/start'));
});

it('replaces the existing container so a redeploy is idempotent', function (): void {
    fakeDockerEngine();

    app(StorefrontProvisioner::class)->provision(storefrontRequest());

    Http::assertSent(fn(Request $request): bool => 'DELETE' === $request->method()
        && Str::contains($request->url(), '/containers/vendra-storefront-acme-flowers')
        && Str::contains($request->url(), 'force=true'));
});

it('routes the container with traefik labels the proxy already understands', function (): void {
    fakeDockerEngine();

    app(StorefrontProvisioner::class)->provision(storefrontRequest());

    Http::assertSent(function (Request $request): bool {
        if ( ! Str::contains($request->url(), '/containers/create')) {
            return false;
        }

        $labels = $request->data()['Labels'];

        return 'true' === $labels['traefik.enable']
            && 'traefik-public' === $labels['traefik.docker.network']
            && 'Host(`acme.test`) || Host(`www.acme.test`)' === $labels['traefik.http.routers.acme-flowers.rule']
            && 'websecure' === $labels['traefik.http.routers.acme-flowers.entrypoints']
            && 'letsencrypt' === $labels['traefik.http.routers.acme-flowers.tls.certresolver']
            && '3000' === $labels['traefik.http.services.acme-flowers.loadbalancer.server.port']
            && '/api/health' === $labels['traefik.http.services.acme-flowers.loadbalancer.healthcheck.path']
            && 'vendra' === $labels['io.vendra.managed-by']
            && 'acme.test' === $labels['io.vendra.domain'];
    });
});

it('passes the encoded configuration and estate settings as container environment', function (): void {
    fakeDockerEngine();

    $request = storefrontRequest();

    app(StorefrontProvisioner::class)->provision($request);

    Http::assertSent(function (Request $sent) use ($request): bool {
        if ( ! Str::contains($sent->url(), '/containers/create')) {
            return false;
        }

        $data = $sent->data();

        return in_array('STOREFRONT_CONFIG_BASE64=' . $request->encodedConfiguration(), $data['Env'], true)
            && in_array('VENDRA_API_URL=https://api.vendra.test', $data['Env'], true)
            && in_array('NODE_EXTRA_CA_CERTS=/certs/vendra-ca.crt', $data['Env'], true)
            && in_array('NODE_ENV=production', $data['Env'], true)
            && ['/var/lib/vendra/certificates:/certs:ro'] === $data['HostConfig']['Binds']
            && 'traefik-public' === $data['HostConfig']['NetworkMode']
            && ['no-new-privileges:true'] === $data['HostConfig']['SecurityOpt'];
    });
});

it('records the deployment as requested when the container never turns healthy', function (): void {
    Config::set('vendra-property.storefront.health_timeout', 1);
    fakeDockerEngine(['Status' => 'running', 'Health' => ['Status' => 'starting']]);
    $deployment = StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'domain'        => 'acme.test',
        'theme'         => 'default',
        'configuration' => storefrontConfiguration(),
    ]);

    runProvisionJob($deployment);

    expect($deployment->refresh()->status)->toBe(StorefrontDeploymentStatus::Requested)
        ->and($deployment->deployed_at)->toBeNull()
        ->and($deployment->container_name)->toBe('vendra-storefront-acme-flowers');
});

it('keeps a retrying deployment out of the failed state until the queue gives up', function (): void {
    fakeDockerEngine(['Status' => 'exited', 'ExitCode' => 1]);
    $deployment = StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'domain'        => 'acme.test',
        'theme'         => 'default',
        'configuration' => storefrontConfiguration(),
    ]);

    $job = new ProvisionStorefrontJob($deployment->id);

    expect(fn() => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class, 'exited while starting');

    // A thrown attempt is not a failed deployment: it is still Processing and
    // the queue will come back to it.
    expect($deployment->refresh()->status)->toBe(StorefrontDeploymentStatus::Processing)
        ->and($deployment->error)->toBeNull();

    $job->failed(new RuntimeException('The storefront container exited during provisioning with code 1.'));

    expect($deployment->refresh()->status)->toBe(StorefrontDeploymentStatus::Failed)
        ->and($deployment->error)->toContain('exited during provisioning')
        ->and($deployment->failed_at)->not->toBeNull();
});

it('refuses to invent a network the estate owns', function (): void {
    fakeDockerEngine(networkExists: false);

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
        ->toThrow(RuntimeException::class, 'does not exist');

    Http::assertNotSent(fn(Request $request): bool => Str::contains($request->url(), '/containers/create'));
});

it('names the daemon it asked when a network is missing', function (): void {
    fakeDockerEngine(networkExists: false, serverHeader: 'Docker/29.7.2 (linux)');

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
        ->toThrow(RuntimeException::class, 'does not exist on http://provisioner:8080 (Docker/29.7.2 (linux))');
});

/*
 | The incident this reports on: an endpoint that had been moved to the other
 | daemon still pinged, so the only symptom was a network that was plainly there
 | when the operator looked for it — on the runtime nobody was talking to.
 */
it('blames the daemon rather than the network when the endpoint is serving the other runtime', function (): void {
    fakeDockerEngine(networkExists: false, serverHeader: 'Libpod/5.8.6 (linux)');

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
        ->toThrow(RuntimeException::class, 'serving podman while CONTAINER_RUNTIME is set to docker');
});

it('does not blame the daemon when the engine is the configured one', function (): void {
    fakeDockerEngine(networkExists: false, serverHeader: 'Docker/29.7.2 (linux)');

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
        ->toThrow(fn(RuntimeException $exception) => expect($exception->getMessage())->not->toContain('CONTAINER_RUNTIME'));
});

it('rejects a configuration the storefront image would refuse to boot on', function (): void {
    fakeDockerEngine();
    $configuration = storefrontConfiguration();
    unset($configuration['businessType'], $configuration['contact']['email']);

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest([
        'configuration' => $configuration,
    ])))->toThrow(InvalidArgumentException::class, 'businessType, contact.email');

    Http::assertNothingSent();
});

it('rejects a theme no published image carries', function (): void {
    fakeDockerEngine();

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest([
        'theme'         => 'midnight',
        'configuration' => storefrontConfiguration(['theme' => 'midnight']),
    ])))->toThrow(InvalidArgumentException::class, 'Unsupported storefront theme [midnight]');
});

it('rejects a configuration whose identity drifted from the deployment', function (): void {
    fakeDockerEngine();

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest([
        'configuration' => storefrontConfiguration(['domain' => 'other.test']),
    ])))->toThrow(InvalidArgumentException::class, 'identity does not match');
});

it('resolves the digest from the pulled image when the reference is only a tag', function (): void {
    $created = false;

    Http::fake(function (Request $request) use (&$created) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (Str::endsWith($path, '/containers/create')) {
            $created = true;

            return Http::response(['Id' => 'container-abc'], 201);
        }

        return match (true) {
            Str::endsWith($path, '/_ping')                                        => Http::response('OK'),
            Str::contains($path, '/networks/')                                    => Http::response(['Name' => 'traefik-public']),
            Str::endsWith($path, '/images/create')                                => Http::response('{"status":"Pulled"}'),
            Str::contains($path, '/images/')                                      => Http::response(['RepoDigests' => ['ghcr.io/misaf/vendra-storefront-florist@sha256:resolved']]),
            Str::endsWith($path, '/start')                                        => Http::response('', 204),
            Str::contains($path, '/containers/') && Str::endsWith($path, '/json') => $created
                ? Http::response([
                    'Id'    => 'container-abc',
                    'Name'  => '/vendra-storefront-acme-flowers',
                    'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
                ])
                : Http::response(['message' => 'no such container'], 404),
            default => Http::response('', 404),
        };
    });

    $result = app(StorefrontProvisioner::class)->provision(storefrontRequest([
        'image' => 'ghcr.io/misaf/vendra-storefront-florist:1.x',
    ]));

    expect($result->imageDigest)->toBe('sha256:resolved');
});

it('surfaces a pull failure reported inside the progress stream', function (): void {
    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            Str::endsWith($path, '/_ping')         => Http::response('OK'),
            Str::contains($path, '/networks/')     => Http::response(['Name' => 'traefik-public']),
            Str::endsWith($path, '/images/create') => Http::response("{\"status\":\"Pulling\"}\n{\"error\":\"manifest unknown\"}"),
            default                                => Http::response('', 404),
        };
    });

    expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
        ->toThrow(RuntimeException::class, 'manifest unknown');
});

describe('podman compatibility', function (): void {
    it('applies the docker log driver and its rotation limits by default', function (): void {
        fakeDockerEngine();

        app(StorefrontProvisioner::class)->provision(storefrontRequest());

        Http::assertSent(function (Request $request): bool {
            if ( ! Str::contains($request->url(), '/containers/create')) {
                return false;
            }

            return [
                'Type'   => 'json-file',
                'Config' => ['max-size' => '10m', 'max-file' => '5'],
            ] === $request->data()['HostConfig']['LogConfig'];
        });
    });

    it('leaves logging to the runtime when no driver is named', function (): void {
        // Podman rejects json-file's options rather than ignoring them, so an
        // empty driver has to omit the block entirely, not send an empty one.
        Config::set('vendra-property.storefront.log_driver', '');
        fakeDockerEngine();

        app(StorefrontProvisioner::class)->provision(storefrontRequest());

        Http::assertSent(function (Request $request): bool {
            if ( ! Str::contains($request->url(), '/containers/create')) {
                return false;
            }

            return ! array_key_exists('LogConfig', $request->data()['HostConfig']);
        });
    });

    it('sends a runtime-specific log driver without docker log options', function (): void {
        Config::set('vendra-property.storefront.log_driver', 'k8s-file');
        Config::set('vendra-property.storefront.log_options', []);
        fakeDockerEngine();

        app(StorefrontProvisioner::class)->provision(storefrontRequest());

        Http::assertSent(function (Request $request): bool {
            if ( ! Str::contains($request->url(), '/containers/create')) {
                return false;
            }

            return ['Type' => 'k8s-file'] === $request->data()['HostConfig']['LogConfig'];
        });
    });

    it('separates an unsupported api version from an unreachable socket', function (): void {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return Str::startsWith($path, '/v1.43')
                ? Http::response(['message' => 'client version 1.43 is too new'], 400)
                : Http::response('OK');
        });

        expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
            ->toThrow(RuntimeException::class, 'CONTAINER_API_VERSION');
    });

    it('reports an unreachable socket as unreachable', function (): void {
        Http::fake(fn(): PromiseInterface => Http::response('', 500));

        expect(fn() => app(StorefrontProvisioner::class)->provision(storefrontRequest()))
            ->toThrow(RuntimeException::class, 'is not reachable');
    });

    it('deploys and warns when the runtime never runs the health check', function (): void {
        // Podman executes health checks through transient systemd timers; with
        // no systemd the state stays empty forever. The storefront still runs,
        // so it deploys — but the lost gate must not be silent.
        Log::spy();
        fakeDockerEngine(['Status' => 'running']);

        $result = app(StorefrontProvisioner::class)->provision(storefrontRequest());

        expect($result->ready)->toBeTrue();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $message): bool => Str::contains($message, 'no health state'))
            ->once();
    });
});
