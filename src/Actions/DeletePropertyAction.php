<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraTenant\Models\Tenant;
use Throwable;

/**
 * Deletes a property, taking its storefront down first.
 *
 * The lifecycle is explicit rather than left to Eloquent's cascade: the cascade
 * would remove the deployment row and leave the container running with nothing
 * left that knows its name — an orphan serving a domain the platform no longer
 * believes it owns.
 *
 * The two halves cannot be one transaction. A container removal is not
 * rollback-able, so it happens first and outside; only once the runtime is
 * settled do the business records go, inside a transaction of their own. A
 * failure in between leaves a property whose storefront is gone, which the
 * operator can retry — the reverse would leak infrastructure silently.
 */
final class DeletePropertyAction
{
    public function __construct(private readonly DestroyPropertyStorefrontAction $destroyStorefront) {}

    /**
     * @param bool $force delete the property permanently rather than soft-deleting it
     */
    public function execute(Tenant $property, bool $force = false): void
    {
        $deployment = StorefrontDeployment::query()
            ->where('tenant_id', $property->getKey())
            ->first();

        if (null !== $deployment) {
            $this->destroyStorefront->execute($deployment);
        }

        DB::transaction(function () use ($property, $force): void {
            $force ? $property->forceDelete() : $property->delete();
        });
    }

    /**
     * Delete the property even when its storefront cannot be reached.
     *
     * Offboarding must not be blocked by a runtime that is down, so the failure
     * is recorded and the business records still go. The container is then a
     * known orphan named in the log rather than an unknown one.
     */
    public function executeIgnoringStorefrontFailures(Tenant $property, bool $force = false): void
    {
        try {
            $this->execute($property, $force);
        } catch (Throwable $exception) {
            Log::warning('Deleting a property storefront failed; deleting the property anyway.', [
                'property_id' => $property->getKey(),
                'exception'   => $exception->getMessage(),
            ]);

            DB::transaction(function () use ($property, $force): void {
                $force ? $property->forceDelete() : $property->delete();
            });
        }
    }
}
