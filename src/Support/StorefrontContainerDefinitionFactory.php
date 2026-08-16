<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use JsonException;
use Misaf\VendraContainer\ValueObjects\ContainerDefinition;
use Misaf\VendraContainer\ValueObjects\EnvironmentVariable;
use Misaf\VendraContainer\ValueObjects\HealthCheck;
use Misaf\VendraContainer\ValueObjects\ImageReference;
use Misaf\VendraContainer\ValueObjects\LogConfiguration;
use Misaf\VendraContainer\ValueObjects\PortBinding;
use Misaf\VendraContainer\ValueObjects\RestartPolicy;
use Misaf\VendraContainer\ValueObjects\VolumeMount;

/**
 * Turns a storefront deployment request plus the estate's settings into the
 * container definition that should run for it.
 *
 * This is the seam between the two layers: everything above it is a property
 * with a domain, a theme, and a configuration; everything below it is a
 * container the runtime knows how to place. A mapper, not a builder — two typed
 * inputs in, one typed value out, no state of its own beyond the settings it is
 * constructed with.
 *
 * What it deliberately does not do is speak Docker. Nanoseconds, `KEY=VALUE`
 * environment strings, and the Engine's port keys live in `vendra-container`;
 * this names a health check, an environment map, and a port.
 */
final class StorefrontContainerDefinitionFactory
{
    /**
     * The label a platform-placed storefront carries, and its value.
     *
     * Checked before anything is replaced or removed: the platform manages the
     * containers it created and never touches one somebody else put on the same
     * runtime.
     *
     * A container's labels cannot be edited in place, so changing this value
     * makes every container already carrying the old one unrecognisable — the
     * platform will refuse to replace them rather than adopt them. Any change
     * here has to be paired with destroying and redeploying the estate.
     */
    public const string MANAGED_BY_LABEL = 'io.vendra.managed-by';

    public const string MANAGED_BY = 'vendra';

    public function __construct(private readonly StorefrontSettings $settings) {}

    /**
     * @throws JsonException
     */
    public function build(StorefrontProvisionRequest $request): ContainerDefinition
    {
        $port = $this->settings->port;

        return new ContainerDefinition(
            name: $this->settings->containerName($request->slug),
            image: new ImageReference($request->image),
            environment: EnvironmentVariable::collection($this->environment($request)),
            labels: $this->labels($request->slug, $request->domain, $port),
            ports: [new PortBinding($port)],
            volumes: $this->volumes(),
            networks: [$this->settings->network],
            healthCheck: $this->healthCheck($port),
            restartPolicy: RestartPolicy::unlessStopped(),
            logConfiguration: new LogConfiguration($this->settings->logDriver, $this->settings->logOptions),
            securityOptions: ['no-new-privileges:true'],
        );
    }

    /**
     * @return array<string, string>
     *
     * @throws JsonException
     */
    private function environment(StorefrontProvisionRequest $request): array
    {
        return [
            'NODE_ENV'                 => 'production',
            'STOREFRONT_CONFIG_BASE64' => $request->encodedConfiguration(),
            'VENDRA_API_URL'           => $this->settings->resolvedApiUrl(),

            /*
             | When the estate terminates TLS with a certificate no public root
             | signs, Node rejects every server-side call to the API with
             | DEPTH_ZERO_SELF_SIGNED_CERT while the page still renders — the
             | failure shows only as empty sections. Empty whenever the system
             | roots already cover the API, which is the common case.
             */
            'NODE_EXTRA_CA_CERTS' => $this->settings->resolvedCaFile(),

            'STORAGE_BASE_URL'   => $this->settings->storageBaseUrl,
            'NESHAN_SERVICE_KEY' => $this->settings->neshanServiceKey,
        ];
    }

    /**
     * Traefik routing, plus the markers that say who placed the container.
     *
     * The load balancer health check matters as much as the container's own:
     * without it Traefik keeps routing to a container the runtime already knows
     * is unhealthy, so a bad deploy 502s instead of draining.
     *
     * @return array<string, string>
     */
    private function labels(string $slug, string $domain, int $port): array
    {
        $healthPath = $this->settings->healthPath;

        $labels = [
            'traefik.enable'         => 'true',
            'traefik.docker.network' => $this->settings->network,

            "traefik.http.services.{$slug}.loadbalancer.server.port"          => (string) $port,
            "traefik.http.services.{$slug}.loadbalancer.healthcheck.path"     => $healthPath,
            "traefik.http.services.{$slug}.loadbalancer.healthcheck.interval" => '10s',
            "traefik.http.services.{$slug}.loadbalancer.healthcheck.timeout"  => '3s',

            "traefik.http.routers.{$slug}.rule"        => sprintf('Host(`%s`) || Host(`www.%s`)', $domain, $domain),
            "traefik.http.routers.{$slug}.entrypoints" => 'websecure',
            "traefik.http.routers.{$slug}.tls"         => 'true',

            // Ownership markers, so the platform can tell a container it placed
            // from one it did not and never replaces or removes somebody else's.
            self::MANAGED_BY_LABEL => self::MANAGED_BY,
            'io.vendra.slug'       => $slug,
            'io.vendra.domain'     => $domain,
        ];

        if ('' !== $this->settings->certResolver) {
            $labels["traefik.http.routers.{$slug}.tls.certresolver"] = $this->settings->certResolver;
        }

        if ('' !== $this->settings->traefikMiddlewares) {
            $labels["traefik.http.routers.{$slug}.middlewares"] = $this->settings->traefikMiddlewares;
        }

        return $labels;
    }

    private function healthCheck(int $port): HealthCheck
    {
        $probe = sprintf(
            "fetch('http://127.0.0.1:%d%s').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))",
            $port,
            $this->settings->healthPath,
        );

        return new HealthCheck(
            test: ['CMD', 'node', '-e', $probe],
            intervalSeconds: 10,
            timeoutSeconds: 3,
            retries: 12,
            startPeriodSeconds: 15,
        );
    }

    /**
     * @return list<VolumeMount>
     */
    private function volumes(): array
    {
        $certificates = $this->settings->certificatesPath;

        return '' === $certificates ? [] : [VolumeMount::readOnly($certificates, '/certs')];
    }
}
