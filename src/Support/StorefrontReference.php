<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use InvalidArgumentException;
use Misaf\VendraProperty\Models\StorefrontDeployment;

/**
 * Which storefront a lifecycle call is about.
 *
 * The slug is the platform's stable handle for a storefront: the container name
 * derives from it, so start/stop/destroy/status need nothing else. It is a value
 * rather than a bare string so a lifecycle port cannot be called with a domain,
 * an id, or a container name by mistake.
 */
final class StorefrontReference
{
    public function __construct(public readonly string $slug)
    {
        if ('' === mb_trim($slug)) {
            throw new InvalidArgumentException('A storefront slug is required.');
        }
    }

    public static function for(StorefrontDeployment $deployment): self
    {
        return new self($deployment->slug);
    }
}
