<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Enums;

use Misaf\VendraContainer\Enums\ContainerHealth;
use Misaf\VendraContainer\ValueObjects\ContainerInfo;

/**
 * What is actually running for a storefront right now.
 *
 * The deployment row records what the platform *decided*; this reports what the
 * runtime *has*. They disagree whenever somebody stops a container by hand, a
 * host reboots, or a deployment failed halfway — which is exactly what
 * reconciliation exists to find.
 */
enum StorefrontRuntimeState: string
{
    case Absent = 'absent';
    case Created = 'created';
    case Running = 'running';
    case Unhealthy = 'unhealthy';
    case Stopped = 'stopped';
    case Unknown = 'unknown';

    /**
     * Reduce a container's reported state to the storefront's own vocabulary.
     *
     * A null container is Absent rather than an error: "there is nothing there"
     * is a legitimate answer to a status question and the common one before a
     * first deployment.
     */
    public static function fromContainer(?ContainerInfo $container): self
    {
        if (null === $container) {
            return self::Absent;
        }

        if ($container->state->hasStopped()) {
            return self::Stopped;
        }

        if ( ! $container->isRunning()) {
            return match ($container->state->value) {
                'created' => self::Created,
                default   => self::Unknown,
            };
        }

        return ContainerHealth::Unhealthy === $container->health
            ? self::Unhealthy
            : self::Running;
    }

    /**
     * Whether the storefront is up and nothing contradicts it.
     */
    public function isServing(): bool
    {
        return self::Running === $this;
    }
}
