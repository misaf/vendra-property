<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Misaf\VendraProperty\Actions\DeployPropertyStorefrontAction;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Runs one storefront deployment off the request lifecycle.
 *
 * Orchestration only: the deployment decision belongs to
 * {@see DeployPropertyStorefrontAction} and the status transitions to the model.
 * The job decides *when* that happens, how often it is retried, and what a final
 * failure means.
 */
final class ProvisionStorefrontJob implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    /**
     * The only queue whose worker holds container-runtime credentials.
     *
     * Deploying needs a runtime socket, which is root-equivalent on the host
     * under Docker. Isolating it here means one small worker container carries
     * that access instead of every queued job in the system sharing it. Horizon's
     * supervisor deliberately does not list this queue.
     */
    public const string QUEUE = 'storefronts';

    public function __construct(
        public readonly int $deploymentId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(DeployPropertyStorefrontAction $deploy): void
    {
        $deployment = StorefrontDeployment::query()->findOrFail($this->deploymentId);

        $deploy->execute($deployment, force: $this->force);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    /**
     * Record the terminal failure.
     *
     * Only here, never in a catch around {@see handle()}: a thrown attempt that
     * still has retries left is not a failed deployment, and writing Failed on
     * every attempt made the panel show a deployment as dead while the queue was
     * still working on it.
     */
    public function failed(?Throwable $exception): void
    {
        StorefrontDeployment::query()->find($this->deploymentId)?->markFailed(
            null === $exception ? 'Storefront provisioning failed.' : $exception->getMessage(),
        );
    }
}
