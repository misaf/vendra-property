<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Support\StorefrontReference;

/**
 * Removes the workload behind a storefront, then the row that described it.
 *
 * Order matters and is deliberate: the container goes first, because a database
 * row deleted while its container keeps serving is an orphan nothing will ever
 * find again. The reverse — a removed container and a surviving row — is
 * recoverable by redeploying.
 *
 * There is no transaction around this. A runtime cannot participate in one, and
 * pretending otherwise would only mean a rolled-back delete leaving a container
 * that is already gone.
 */
final class DestroyPropertyStorefrontAction
{
    public function __construct(private readonly StorefrontProvisioner $provisioner) {}

    public function execute(StorefrontDeployment $deployment): void
    {
        $this->provisioner->destroy(StorefrontReference::for($deployment));

        $deployment->delete();
    }
}
