<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Observers;

use Misaf\VendraProperty\Support\StorefrontOrigins;
use Misaf\VendraTenant\Models\TenantDomain;

/**
 * Keeps the API's CORS allowlist in step with the domains that back it.
 *
 * A domain going active or inactive changes who may call the API, so the cached
 * allowlist must not outlive the change. This is model-lifecycle behaviour and
 * belongs in an observer rather than as two closures in the service provider's
 * boot method.
 */
final class TenantDomainObserver
{
    public function saved(TenantDomain $domain): void
    {
        StorefrontOrigins::forget();
    }

    public function deleted(TenantDomain $domain): void
    {
        StorefrontOrigins::forget();
    }
}
