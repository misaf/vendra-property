<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Contracts;

use Misaf\VendraProperty\Support\StorefrontObservation;
use Misaf\VendraProperty\Support\StorefrontProvisionRequest;
use Misaf\VendraProperty\Support\StorefrontProvisionResult;
use Misaf\VendraProperty\Support\StorefrontReference;

/**
 * The port between the property layer and whatever actually runs a storefront.
 *
 * Every value crossing it is typed, so an adapter can be written against this
 * interface alone — the array-in/array-out shape it replaced documented nothing
 * and forced every caller to re-validate what it received.
 *
 * The property layer decides *when* a storefront should be deployed, stopped, or
 * destroyed and what it should contain; an implementation decides how a runtime
 * is made to agree. Nothing here mentions containers, because a future
 * implementation need not use them.
 */
interface StorefrontProvisioner
{
    /**
     * Place the storefront described by the request, replacing any predecessor.
     *
     * Idempotent by contract: calling it twice with the same request leaves one
     * storefront running, which is what reconciliation and retry depend on.
     */
    public function provision(StorefrontProvisionRequest $request): StorefrontProvisionResult;

    /**
     * Bring an already-deployed storefront back up. Already running is success.
     */
    public function start(StorefrontReference $storefront): void;

    /**
     * Take a storefront down without discarding it. Already stopped is success.
     */
    public function stop(StorefrontReference $storefront): void;

    public function restart(StorefrontReference $storefront): void;

    /**
     * Remove the storefront entirely. Absent is success.
     */
    public function destroy(StorefrontReference $storefront): void;

    /**
     * What is actually running for this storefront right now.
     *
     * Reconciliation decides what to do from this and the deployment row alone,
     * so it must report enough to distinguish "stopped" from "running the wrong
     * image" — a bare state enum forced a redeploy to establish either.
     *
     * Implementations must not answer "absent" for a runtime they could not
     * reach: a converge pass would read that as a missing storefront and rebuild
     * a healthy one.
     */
    public function observe(StorefrontReference $storefront): StorefrontObservation;

    /**
     * Recent output from the storefront, for diagnosing a failed deployment.
     */
    public function logs(StorefrontReference $storefront, int $lines = 200): string;
}
