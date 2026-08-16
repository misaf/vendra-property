<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Misaf\VendraSupport\Tenancy\Scopes\TeamScope;
use Misaf\VendraSupport\Tenancy\Scopes\TenantScope;
use Misaf\VendraTenant\Models\TenantDomain;

/**
 * The CORS allowlist for the canonical API.
 *
 * Storefronts are served on customer domains but fetch their data from
 * https://api.<base>, so every browser call is cross-origin. The allowlist
 * therefore has to be data, not config: it changes whenever a property is
 * onboarded. A wildcard is not an option — it cannot be combined with
 * credentials, and it would let any site on the internet read the API through a
 * visitor's browser.
 */
final class StorefrontOrigins
{
    public const string CACHE_KEY = 'cors:storefront-origins';

    /**
     * Active storefront origins, e.g. ['https://abbas.com', 'https://www.abbas.com'].
     *
     * @return list<string>
     */
    public function all(): array
    {
        /** @var list<string> $origins */
        $origins = Cache::rememberForever(self::CACHE_KEY, fn(): array => $this->query());

        return $origins;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    private function query(): array
    {
        try {
            $domains = TenantDomain::query()
                ->withoutGlobalScopes([TenantScope::class, TeamScope::class])
                ->where('active', true)
                ->pluck('name');
        } catch (QueryException) {
            // No database yet (fresh install, pre-migration CI). An empty
            // allowlist denies every cross-origin call, which is the safe way to
            // fail: it never widens access.
            return [];
        }

        return array_values(
            $domains
                ->flatMap(function (mixed $name): array {
                    if ( ! is_string($name) || '' === $name) {
                        return [];
                    }

                    return [
                        'https://' . $name,
                        'https://www.' . $name,
                    ];
                })
                ->unique()
                ->all(),
        );
    }
}
