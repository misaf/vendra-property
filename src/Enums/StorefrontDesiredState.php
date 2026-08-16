<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Enums;

/**
 * What the platform intends for a storefront, independent of what is running.
 *
 * Without this, "stopped" and "failed to start" are the same row, and
 * reconciliation cannot tell a storefront somebody deliberately stopped from one
 * that fell over — so it would start the first back up on every pass.
 */
enum StorefrontDesiredState: string
{
    case Running = 'running';
    case Stopped = 'stopped';

    /**
     * Whether reconciliation should be trying to get this storefront serving.
     */
    public function expectsRunning(): bool
    {
        return self::Running === $this;
    }
}
