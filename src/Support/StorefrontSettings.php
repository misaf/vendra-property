<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * The `vendra-property.storefront` block as an immutable, typed value.
 *
 * Every consumer used to reach into `Config` and re-implement the same
 * string/int/bool coercion, so the shape of the block was described three times
 * and enforced nowhere. Reading it once, here, means the deployment layer and
 * the container-definition factory receive settled values instead of an untyped
 * array and a private accessor triplet each.
 *
 * Note what is *not* here: the runtime endpoint and its API version. Those are
 * `vendra-container` settings, and a property does not know what a socket is.
 */
final class StorefrontSettings
{
    /**
     * @param list<string>          $themes
     * @param array<string, string> $logOptions
     */
    public function __construct(
        public readonly string $image,
        public readonly array $themes,
        public readonly string $network,
        public readonly string $namePrefix,
        public readonly int $port,
        public readonly string $healthPath,
        public readonly int $healthTimeout,
        public readonly bool $pull,
        public readonly string $logDriver,
        public readonly array $logOptions,
        public readonly string $baseDomain,
        public readonly string $apiUrl,
        public readonly string $certResolver,
        public readonly string $certificatesPath,
        public readonly string $caFile,
        public readonly string $storageBaseUrl,
        public readonly string $neshanServiceKey,
        public readonly string $traefikMiddlewares,
    ) {}

    /**
     * Read the current configuration.
     *
     * Bound as a non-singleton so a configuration change — a test setting an
     * image, an operator reloading config — is picked up on the next resolve
     * rather than frozen at first use.
     */
    public static function fromConfig(): self
    {
        $storefront = Config::array('vendra-property.storefront');

        return new self(
            image: self::string($storefront, 'image'),
            themes: self::themes($storefront),
            network: self::string($storefront, 'network', 'traefik-public'),
            namePrefix: self::string($storefront, 'name_prefix', 'vendra-storefront-'),
            port: self::integer($storefront, 'port', 3000),
            healthPath: self::string($storefront, 'health_path', '/api/health'),
            healthTimeout: self::integer($storefront, 'health_timeout', 120),
            pull: filter_var(Arr::get($storefront, 'pull'), FILTER_VALIDATE_BOOL),
            logDriver: self::string($storefront, 'log_driver'),
            logOptions: self::logOptions($storefront),
            baseDomain: self::string($storefront, 'base_domain'),
            apiUrl: self::string($storefront, 'api_url'),
            certResolver: self::string($storefront, 'cert_resolver'),
            certificatesPath: self::string($storefront, 'certificates_path'),
            caFile: self::string($storefront, 'ca_file'),
            storageBaseUrl: self::string($storefront, 'storage_base_url'),
            neshanServiceKey: self::string($storefront, 'neshan_service_key'),
            traefikMiddlewares: self::string($storefront, 'traefik_middlewares'),
        );
    }

    public function containerName(string $slug): string
    {
        return $this->namePrefix . $slug;
    }

    /**
     * The API origin storefront containers call, explicit or derived.
     */
    public function resolvedApiUrl(): string
    {
        if ('' !== $this->apiUrl) {
            return $this->apiUrl;
        }

        return '' === $this->baseDomain ? '' : 'https://api.' . $this->baseDomain;
    }

    /**
     * The CA bundle path inside the container.
     *
     * Resolved against the read-only certificate mount, so operators configure a
     * file name rather than an in-container path they cannot see.
     */
    public function resolvedCaFile(): string
    {
        if ('' === $this->caFile) {
            return '';
        }

        return str_starts_with($this->caFile, '/') ? $this->caFile : '/certs/' . $this->caFile;
    }

    /**
     * @param  array<array-key, mixed> $storefront
     * @return list<string>
     */
    private static function themes(array $storefront): array
    {
        $themes = [];
        $configured = Arr::get($storefront, 'themes');

        foreach (is_array($configured) ? $configured : [] as $theme) {
            if (is_string($theme) && '' !== $theme) {
                $themes[] = $theme;
            }
        }

        return [] === $themes ? ['default'] : $themes;
    }

    /**
     * @param  array<array-key, mixed> $storefront
     * @return array<string, string>
     */
    private static function logOptions(array $storefront): array
    {
        $options = Arr::get($storefront, 'log_options');
        $resolved = [];

        foreach (is_array($options) ? $options : [] as $key => $value) {
            if (is_string($key) && is_scalar($value) && '' !== (string) $value) {
                $resolved[$key] = (string) $value;
            }
        }

        return $resolved;
    }

    /**
     * @param array<array-key, mixed> $storefront
     */
    private static function string(array $storefront, string $key, string $default = ''): string
    {
        $value = Arr::get($storefront, $key);

        return is_string($value) && '' !== mb_trim($value) ? mb_trim($value) : $default;
    }

    /**
     * @param array<array-key, mixed> $storefront
     */
    private static function integer(array $storefront, string $key, int $default): int
    {
        $value = Arr::get($storefront, $key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
