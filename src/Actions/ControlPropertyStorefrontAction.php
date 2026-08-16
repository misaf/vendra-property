<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Support\StorefrontObservation;
use Misaf\VendraProperty\Support\StorefrontReference;

/**
 * Starts, stops, and restarts an already-deployed storefront.
 *
 * These are not deployments: nothing is built, pulled, or replaced, so the
 * recorded status and image stay exactly as they were. What does change is the
 * desired state, so a storefront somebody stopped on purpose is not started
 * again by the next reconciliation pass.
 */
final class ControlPropertyStorefrontAction
{
    public function __construct(private readonly StorefrontProvisioner $provisioner) {}

    public function start(StorefrontDeployment $deployment): void
    {
        $this->provisioner->start(StorefrontReference::for($deployment));

        $deployment->markDesiredState(StorefrontDesiredState::Running);
    }

    public function stop(StorefrontDeployment $deployment): void
    {
        $this->provisioner->stop(StorefrontReference::for($deployment));

        $deployment->markDesiredState(StorefrontDesiredState::Stopped);
    }

    public function restart(StorefrontDeployment $deployment): void
    {
        $this->provisioner->restart(StorefrontReference::for($deployment));

        $deployment->markDesiredState(StorefrontDesiredState::Running);
    }

    /**
     * What is actually running for this storefront right now.
     */
    public function observe(StorefrontDeployment $deployment): StorefrontObservation
    {
        return $this->provisioner->observe(StorefrontReference::for($deployment));
    }

    /**
     * Recent output, for diagnosing a storefront that will not come up.
     */
    public function logs(StorefrontDeployment $deployment, int $lines = 200): string
    {
        return $this->provisioner->logs(StorefrontReference::for($deployment), $lines);
    }
}
