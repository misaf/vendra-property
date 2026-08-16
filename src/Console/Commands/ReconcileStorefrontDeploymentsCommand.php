<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Console\Commands;

use Illuminate\Database\Eloquent\Builder;
use Misaf\VendraProperty\Actions\ReconcilePropertyStorefrontAction;
use Misaf\VendraProperty\Enums\StorefrontReconciliationOutcome;
use Misaf\VendraProperty\Jobs\ReconcileStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;

/**
 * Makes the estate match what the database says it should be.
 *
 * Every deployment is examined, including the ones intended to be stopped: a
 * storefront still running against a Stopped intent is drift in the same way a
 * missing one is, and only a pass that looks at both can fix either.
 *
 * This is cheap and safe to repeat. It corrects with the narrowest verb that
 * works — start a stopped container, stop a running one, redeploy only what is
 * absent, unhealthy, or serving the wrong image — so a converged estate comes
 * through a pass untouched. Use `storefront:redeploy` to rebuild deliberately.
 */
final class ReconcileStorefrontDeploymentsCommand extends StorefrontDeploymentDispatchCommand
{
    protected $signature = 'storefront:reconcile
        {--sync : Reconcile each storefront in the current process}
        {--force-unique : Drop a stale unique lock left by a killed worker; skips the guard against provisioning one storefront twice}';

    protected $description = 'Converge every storefront runtime with the state the database intends';

    /**
     * @return Builder<StorefrontDeployment>
     */
    protected function query(): Builder
    {
        return StorefrontDeployment::query();
    }

    protected function jobFor(int $deploymentId): object
    {
        return new ReconcileStorefrontJob($deploymentId);
    }

    /**
     * Converge directly rather than through the job, so the outcome survives.
     *
     * The queue would swallow it: a queueable job dispatched synchronously
     * returns the sync driver's push result, not what the handler decided.
     */
    protected function performSync(int $deploymentId): mixed
    {
        $deployment = StorefrontDeployment::query()->find($deploymentId);

        return $deployment instanceof StorefrontDeployment
            ? $this->laravel->make(ReconcilePropertyStorefrontAction::class)->execute($deployment)
            : null;
    }

    protected function summary(): string
    {
        return '%d storefront deployment(s) %s.';
    }

    protected function syncVerb(): string
    {
        return 'reconciled';
    }

    protected function queuedVerb(): string
    {
        return 'queued for reconciliation';
    }

    /**
     * A count alone hides the only thing worth knowing about a converge pass:
     * which storefronts were actually touched.
     *
     * @param list<mixed> $outcomes
     */
    protected function reportOutcomes(array $outcomes): void
    {
        $tally = [];

        foreach ($outcomes as $outcome) {
            if ($outcome instanceof StorefrontReconciliationOutcome) {
                $tally[$outcome->value] = ($tally[$outcome->value] ?? 0) + 1;
            }
        }

        if ([] === $tally) {
            return;
        }

        ksort($tally);

        foreach ($tally as $label => $count) {
            $this->line(sprintf('  %s: %d', $label, $count));
        }
    }
}
