<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

/**
 * What a provisioner reports back after placing a storefront.
 *
 * `ready` means the container passed its health gate; anything else deployed but
 * unproven, which the deployment records as "requested" so reconciliation can
 * revisit it. Returning a typed result rather than a loose array is what lets the
 * job write its status without re-checking the shape of every field.
 */
final class StorefrontProvisionResult
{
    public function __construct(
        public readonly bool $ready,
        public readonly ?string $reference = null,
        public readonly ?string $imageDigest = null,
    ) {}

    /**
     * Normalise a reference and digest that may be absent or blank.
     */
    public static function make(bool $ready, ?string $reference, ?string $imageDigest): self
    {
        return new self(
            ready: $ready,
            reference: self::filled($reference),
            imageDigest: self::filled($imageDigest),
        );
    }

    private static function filled(?string $value): ?string
    {
        return null !== $value && '' !== mb_trim($value) ? mb_trim($value) : null;
    }
}
