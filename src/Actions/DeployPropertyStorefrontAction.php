<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Support\StorefrontProvisionRequest;
use Misaf\VendraProperty\Support\StorefrontSettings;

/**
 * Deploys or redeploys one property's storefront, and records what happened.
 *
 * Deploy and redeploy are the same operation: the provisioner replaces whatever
 * is there, so a first deployment, a configuration change, and a moved image tag
 * all take this path. That is what makes retrying safe.
 *
 * Nothing here is transactional with the database on purpose. A container cannot
 * join a SQL transaction, so the row is moved to Processing first, the runtime
 * work happens outside, and the outcome is written afterwards — a crash in
 * between leaves a row that says "in progress", which reconciliation can fix,
 * rather than a committed lie in either direction.
 */
final class DeployPropertyStorefrontAction
{
    public function __construct(
        private readonly StorefrontProvisioner $provisioner,
        private readonly StorefrontSettings $settings,
    ) {}

    /**
     * @param bool $force redeploy even a storefront already recorded as ready
     */
    public function execute(StorefrontDeployment $deployment, bool $force = false): StorefrontDeploymentStatus
    {
        if ( ! $force && StorefrontDeploymentStatus::Ready === $deployment->status) {
            return $deployment->status;
        }

        $deployment->markProcessing();

        $request = StorefrontProvisionRequest::for($deployment, $this->settings);
        $result = $this->provisioner->provision($request);

        if ($result->ready) {
            $deployment->markReady($result->reference, $request->image, $result->imageDigest);
        } else {
            $deployment->markRequested($result->reference, $request->image, $result->imageDigest);
        }

        /*
         | A deployment is an instruction to run, so it also settles the intent:
         | a storefront that was deliberately stopped and is then redeployed is
         | meant to be up again.
         */
        $deployment->markDesiredState(StorefrontDesiredState::Running);

        return $deployment->status;
    }
}
