<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use Misaf\VendraContainer\ValueObjects\ContainerInfo;
use Misaf\VendraProperty\Enums\StorefrontRuntimeState;

/**
 * What the runtime actually has for one storefront, right now.
 *
 * The port used to answer this with a bare state enum, so the only question
 * reconciliation could ask was "is it up?" — and the only way to be sure of
 * anything else was to redeploy. Carrying the image reference alongside the
 * state is what lets a converge pass tell a storefront serving the current
 * release from one still running the previous image.
 */
final class StorefrontObservation
{
    public function __construct(
        public readonly StorefrontRuntimeState $state,
        public readonly ?string $image = null,
        public readonly ?string $containerName = null,
    ) {}

    /**
     * A null container is Absent rather than an error: "there is nothing there"
     * is the honest answer before a first deployment.
     */
    public static function fromContainer(?ContainerInfo $container): self
    {
        return new self(
            state: StorefrontRuntimeState::fromContainer($container),
            image: $container?->image,
            containerName: $container?->name,
        );
    }

    public function isAbsent(): bool
    {
        return StorefrontRuntimeState::Absent === $this->state;
    }

    /**
     * Whether the runtime is serving something other than the given image.
     *
     * Compared as *references* — the string the container was created with
     * against the one settings now name — because those are the only two values
     * here that are comparable at all. A container's `Image` is the runtime's
     * local image id and a deployment's recorded `image_digest` is the registry's
     * repo digest; they never match, so diffing those would report drift on every
     * storefront on every pass and redeploy the whole estate.
     *
     * An unobserved image is not drift: nothing was learned, so nothing changes.
     */
    public function isServingOtherThan(string $image): bool
    {
        return null !== $this->image && $this->image !== $image;
    }
}
