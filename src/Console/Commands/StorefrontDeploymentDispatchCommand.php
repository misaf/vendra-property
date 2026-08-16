<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Console\Commands;

use ArrayObject;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;
use Misaf\VendraContainer\Support\ContainerRuntimeConfiguration;
use Misaf\VendraProperty\Models\StorefrontDeployment;

/**
 * Shared body of the commands that push storefront deployments back through
 * provisioning.
 *
 * Reconcile and retry-failed differ only in which rows they select and whether
 * they override a Ready status, so the selection and those two words are the
 * subclass's entire job — the guard, chunking, sync/queue switch, and reporting
 * are identical and live here.
 */
abstract class StorefrontDeploymentDispatchCommand extends Command
{
    public function handle(ContainerRuntimeConfiguration $runtime): int
    {
        if ( ! $runtime->isConfigured()) {
            $this->error($runtime->misconfigurationMessage());

            return self::FAILURE;
        }

        $count = 0;
        $outcomes = [];
        $skipped = $this->recordSkippedDispatches();

        $this->query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function (Collection $deployments) use (&$count, &$outcomes): void {
                foreach ($deployments as $deployment) {
                    if ($this->option('sync')) {
                        $outcomes[] = $this->performSync($deployment->id);
                    } else {
                        $job = $this->jobFor($deployment->id);

                        if ($this->option('force-unique')) {
                            $this->releaseUniqueLock($job);
                        }

                        dispatch($job);
                    }

                    $count++;
                }
            });

        $this->info(sprintf($this->summary(), $count - $skipped->count(), $this->option('sync') ? $this->syncVerb() : $this->queuedVerb()));
        $this->reportSkipped($skipped);
        $this->reportOutcomes(array_values(array_filter($outcomes)));

        return self::SUCCESS;
    }

    /**
     * Drop a unique lock so this dispatch is accepted.
     *
     * For one situation only: a worker killed mid-provision never releases its
     * lock, so the deployment is unqueueable for the whole `uniqueFor` window —
     * an hour — with no other way out. The lock cannot say whether its owner is
     * dead or merely slow, which is why this is a flag an operator types and not
     * a timeout the command applies on its own: used while a job really is in
     * flight, it lets a second one provision the same storefront concurrently.
     */
    private function releaseUniqueLock(object $job): void
    {
        (new UniqueLock($this->laravel->make(Cache::class)))->release($job);
    }

    /**
     * Collect the deployments the bus refuses to queue a second job for.
     *
     * `ProvisionStorefrontJob` is `ShouldBeUnique`, so dispatching one for a
     * deployment that already has a job in flight is discarded — silently, and
     * correctly: a storefront must not be provisioned twice at once. What was
     * wrong is that this command counted what it *handed to* the bus rather than
     * what the bus took, so a run in which nothing at all was queued still
     * reported every deployment as queued and read as success.
     *
     * The framework announces each discard, which is race-free and costs nothing
     * — unlike inspecting the lock before dispatching, which is both a guess and
     * a second chance to lose the race.
     *
     * @return ArrayObject<int, int> deployment ids; an object so the listener's
     *                               writes are visible to the caller
     */
    private function recordSkippedDispatches(): ArrayObject
    {
        /** @var ArrayObject<int, int> $skipped */
        $skipped = new ArrayObject();

        Event::listen(static function (UniqueJobSkipped $event) use ($skipped): void {
            if (property_exists($event->job, 'deploymentId')) {
                $skipped->append($event->job->deploymentId);
            }
        });

        return $skipped;
    }

    /**
     * @param ArrayObject<int, int> $skipped
     */
    private function reportSkipped(ArrayObject $skipped): void
    {
        if (0 === $skipped->count()) {
            return;
        }

        $ids = $skipped->getArrayCopy();

        $slugs = StorefrontDeployment::query()
            ->whereIn('id', $ids)
            ->orderBy('slug')
            ->pluck('slug')
            ->implode(', ');

        $this->warn(sprintf(
            '%d skipped, already being provisioned: %s. A deployment holds its lock through every retry, so one that '
            . 'is failing and backing off cannot be pushed again until the queue gives up on it.',
            count($ids),
            '' === $slugs ? implode(', ', $ids) : $slugs,
        ));
    }

    /**
     * The deployments this command acts on.
     *
     * @return Builder<StorefrontDeployment>
     */
    abstract protected function query(): Builder;

    /**
     * A `sprintf` template taking the count and the verb.
     */
    abstract protected function summary(): string;

    abstract protected function syncVerb(): string;

    abstract protected function queuedVerb(): string;

    /**
     * The job that carries out this command's intent for one deployment.
     *
     * Each subclass wants a different verb applied — retry provisioning, force a
     * redeploy, converge — so the job is the subclass's choice rather than a flag
     * on one job that has to mean all three.
     */
    abstract protected function jobFor(int $deploymentId): object;

    /**
     * Do the work for one deployment in this process, returning anything worth
     * reporting afterwards.
     *
     * Note what this cannot be: `dispatch_sync()` on a queueable job hands it to
     * the sync *queue driver*, so what comes back is the driver's push result and
     * never the handler's return value. A command that wants an outcome has to
     * override this and call the operation itself.
     */
    protected function performSync(int $deploymentId): mixed
    {
        return dispatch_sync($this->jobFor($deploymentId));
    }

    /**
     * Summarise what a synchronous pass actually did.
     *
     * Only a `--sync` run has anything to report: a queued job has not run yet,
     * so there is no outcome to collect.
     *
     * @param list<mixed> $outcomes
     */
    protected function reportOutcomes(array $outcomes): void {}
}
