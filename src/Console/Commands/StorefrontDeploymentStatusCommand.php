<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Enums\StorefrontRuntimeState;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Support\StorefrontReference;
use Throwable;

final class StorefrontDeploymentStatusCommand extends Command
{
    protected $signature = 'storefront:status {--runtime : Also ask the container runtime what it actually has}';

    protected $description = 'List storefront deployments from the database';

    public function handle(StorefrontProvisioner $provisioner): int
    {
        $deployments = StorefrontDeployment::query()
            ->select(['slug', 'domain', 'status', 'desired_state', 'container_name', 'image_digest'])
            ->orderBy('slug')
            ->get();

        if ($deployments->isEmpty()) {
            $this->info('No storefront deployments found.');

            return self::SUCCESS;
        }

        if ( ! $this->option('runtime')) {
            $this->table(
                ['Slug', 'Domain', 'Status', 'Desired', 'Container', 'Image digest'],
                $deployments->map(fn(StorefrontDeployment $deployment): array => [
                    ...$this->recordedColumns($deployment),
                    $deployment->image_digest ?? '—',
                ])->all(),
            );

            return self::SUCCESS;
        }

        $observed = $deployments->mapWithKeys(
            fn(StorefrontDeployment $deployment): array => [$deployment->slug => $this->observe($provisioner, $deployment)],
        );

        $this->table(
            ['Slug', 'Domain', 'Status', 'Desired', 'Container', 'Runtime'],
            $deployments->map(fn(StorefrontDeployment $deployment): array => [
                ...$this->recordedColumns($deployment),
                $this->describeRuntime($deployment, $observed->get($deployment->slug)),
            ])->all(),
        );

        return $this->reportMissing($deployments, $observed);
    }

    /**
     * @return list<string>
     */
    private function recordedColumns(StorefrontDeployment $deployment): array
    {
        return [
            $deployment->slug,
            $deployment->domain,
            $deployment->status->value,
            $deployment->desired_state->value,
            $deployment->container_name ?? '—',
        ];
    }

    /**
     * A daemon that will not answer is reported per row rather than aborting.
     *
     * The recorded state is still worth printing when the runtime is down, and
     * this command is most useful precisely when something about the runtime is
     * wrong.
     */
    private function observe(StorefrontProvisioner $provisioner, StorefrontDeployment $deployment): ?StorefrontRuntimeState
    {
        try {
            return $provisioner->observe(StorefrontReference::for($deployment))->state;
        } catch (Throwable) {
            return null;
        }
    }

    private function describeRuntime(StorefrontDeployment $deployment, ?StorefrontRuntimeState $state): string
    {
        if (null === $state) {
            return '<fg=yellow>unreachable</>';
        }

        if (StorefrontRuntimeState::Absent !== $state) {
            return $state->value;
        }

        return $deployment->desired_state->expectsRunning()
            ? '<fg=red>absent</>'
            : 'absent';
    }

    /**
     * Say plainly when the runtime has nothing for a storefront it should have.
     *
     * The common cause is not a container that died but a daemon that changed:
     * switching CONTAINER_RUNTIME or CONTAINER_ENDPOINT leaves the old
     * containers running on the old daemon, invisible and unmanaged, while every
     * deployment row reads as though it were fine.
     *
     * @param Collection<int, StorefrontDeployment>               $deployments
     * @param Collection<string, StorefrontRuntimeState|null>     $observed
     */
    private function reportMissing(Collection $deployments, Collection $observed): int
    {
        $missing = $deployments
            ->filter(fn(StorefrontDeployment $deployment): bool => $deployment->desired_state->expectsRunning()
                && StorefrontRuntimeState::Absent === $observed->get($deployment->slug))
            ->pluck('slug');

        if ($missing->isEmpty()) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf(
            'The runtime has no container for: %s. If the endpoint or runtime was changed, those containers are '
            . 'still on the previous daemon — run container:status to confirm which one is answering, then '
            . 'storefront:redeploy to place them here.',
            $missing->implode(', '),
        ));

        return self::FAILURE;
    }
}
