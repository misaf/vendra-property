<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Misaf\VendraProperty\Actions\DestroyPropertyStorefrontAction;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Takes one storefront down off the request lifecycle.
 *
 * Queued for the same reason deployment is: only the storefront worker holds a
 * runtime socket, and an operator closing a property should not wait on a
 * container to stop. It runs on the same queue for that reason.
 */
final class DestroyStorefrontJob implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $deploymentId)
    {
        $this->onQueue(ProvisionStorefrontJob::QUEUE);
    }

    public function handle(DestroyPropertyStorefrontAction $destroy): void
    {
        $deployment = StorefrontDeployment::query()->find($this->deploymentId);

        // Already gone is the outcome this job exists to produce.
        $deployment instanceof StorefrontDeployment ? $destroy->execute($deployment) : null;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    /**
     * A storefront that could not be removed is a leaked container, so it is
     * named in the log rather than failing quietly with the row still present.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Destroying a storefront failed; its container may still be running.', [
            'deployment_id' => $this->deploymentId,
            'exception'     => $exception?->getMessage(),
        ]);
    }
}
