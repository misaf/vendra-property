<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraProperty\Actions\ReconcilePropertyStorefrontAction;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Enums\StorefrontReconciliationOutcome;
use Misaf\VendraProperty\Jobs\ProvisionStorefrontJob;
use Misaf\VendraProperty\Jobs\ReconcileStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;

const RECONCILE_IMAGE = 'ghcr.io/misaf/vendra-storefront-florist@sha256:abc123';

beforeEach(function (): void {
    Config::set('vendra-container.endpoint', 'unix:///var/run/docker.sock');
    Config::set('vendra-property.storefront.image', RECONCILE_IMAGE);
    Config::set('vendra-property.storefront.themes', ['default']);
    Config::set('vendra-property.storefront.network', 'traefik-public');
    // The redeploy paths run a real health gate; without a short budget an
    // unhealthy container is polled for the production default of two minutes.
    Config::set('vendra-property.storefront.health_timeout', 1);
});

/**
 * A configuration complete enough to survive validation on a redeploy.
 *
 * Deliberately local rather than borrowed from the provisioning tests: helpers
 * declared in a sibling test file only exist once that file has been loaded, so
 * reaching across would pass in a full run and fail whenever this file is run on
 * its own.
 *
 * @return array<string, mixed>
 */
function reconcilableConfiguration(): array
{
    return [
        'slug'          => 'acme-flowers',
        'theme'         => 'default',
        'domain'        => 'acme.test',
        'siteUrl'       => 'https://acme.test',
        'businessType'  => 'Florist',
        'priceCurrency' => 'IRR',
        'name'          => ['en' => 'Acme Flowers'],
        'address'       => ['locality' => 'Tehran', 'country' => 'IR'],
        'contact'       => [
            'mobilePhone' => '09120000000',
            'officePhone' => '02100000000',
            'email'       => 'contact@acme.test',
            'hoursOpen'   => '08:00',
            'hoursClose'  => '21:00',
            'mapQuery'    => '35.7,51.4',
        ],
        'social' => [
            'whatsappPhone'     => '+989120000000',
            'telegramUsername'  => 'acmeflowers',
            'instagramUsername' => 'acmeflowers',
        ],
    ];
}

/**
 * A deployment complete enough that redeploying it would really succeed.
 *
 * Reconciliation reaches for a redeploy on several paths, and a half-filled
 * configuration would fail validation there rather than at the branch under test.
 *
 * @param array<string, mixed> $attributes
 */
function reconcilable(array $attributes = []): StorefrontDeployment
{
    return StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'domain'        => 'acme.test',
        'theme'         => 'default',
        'status'        => StorefrontDeploymentStatus::Ready,
        'desired_state' => StorefrontDesiredState::Running,
        'image'         => RECONCILE_IMAGE,
        'configuration' => reconcilableConfiguration(),
        ...$attributes,
    ]);
}

function reconcile(StorefrontDeployment $deployment): StorefrontReconciliationOutcome
{
    return app(ReconcilePropertyStorefrontAction::class)->execute($deployment);
}

describe('a storefront meant to be running', function (): void {
    it('leaves a healthy one serving the configured image completely alone', function (): void {
        $engine = fakeExistingStorefront();

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::InSync)
            ->and($engine->calls)->toBe([]);
    });

    it('starts a stopped storefront instead of rebuilding it', function (): void {
        $engine = fakeExistingStorefront(['Status' => 'exited', 'ExitCode' => 0]);

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::Started)
            ->and($engine->calls)->toBe(['start'])
            ->and($engine->calls)->not->toContain('remove');
    });

    it('deploys one the runtime does not have at all', function (): void {
        $engine = fakeExistingStorefront(present: false);

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::Deployed)
            ->and($engine->calls)->toContain('containers/create');
    });

    it('redeploys one serving an image other than the configured one', function (): void {
        $engine = fakeExistingStorefront(image: 'ghcr.io/misaf/vendra-storefront-florist@sha256:older');

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::Redeployed)
            ->and($engine->calls)->toContain('remove')
            ->and($engine->calls)->toContain('containers/create');
    });

    it('redeploys one that is running but failing its health check', function (): void {
        $engine = fakeExistingStorefront(['Status' => 'running', 'Health' => ['Status' => 'unhealthy']]);

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::Redeployed)
            ->and($engine->calls)->toContain('containers/create');
    });

    it('does not mistake an unreported health state for drift', function (): void {
        $engine = fakeExistingStorefront(['Status' => 'running']);

        expect(reconcile(reconcilable()))->toBe(StorefrontReconciliationOutcome::InSync)
            ->and($engine->calls)->toBe([]);
    });
});

describe('a storefront meant to be stopped', function (): void {
    it('stops one that is still running', function (): void {
        $engine = fakeExistingStorefront();
        $deployment = reconcilable(['desired_state' => StorefrontDesiredState::Stopped]);

        expect(reconcile($deployment))->toBe(StorefrontReconciliationOutcome::Stopped)
            ->and($engine->calls)->toBe(['stop']);
    });

    it('leaves an already stopped one alone rather than starting it', function (): void {
        $engine = fakeExistingStorefront(['Status' => 'exited', 'ExitCode' => 0]);
        $deployment = reconcilable(['desired_state' => StorefrontDesiredState::Stopped]);

        expect(reconcile($deployment))->toBe(StorefrontReconciliationOutcome::InSync)
            ->and($engine->calls)->toBe([]);
    });
});

it('never rewrites the intent it is converging towards', function (): void {
    fakeExistingStorefront(['Status' => 'exited', 'ExitCode' => 0]);
    $deployment = reconcilable(['desired_state' => StorefrontDesiredState::Stopped]);

    reconcile($deployment);

    expect($deployment->refresh()->desired_state)->toBe(StorefrontDesiredState::Stopped);
});

it('refuses to read an unreachable runtime as an absent storefront', function (): void {
    Illuminate\Support\Facades\Http::fake(fn() => Illuminate\Support\Facades\Http::response('boom', 500));

    expect(fn() => reconcile(reconcilable()))->toThrow(RuntimeException::class);
});

describe('the reconcile command', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('queues a convergence for every deployment, stopped ones included', function (): void {
        $running = reconcilable();
        $stopped = reconcilable([
            'slug'          => 'beta-flowers',
            'domain'        => 'beta.test',
            'desired_state' => StorefrontDesiredState::Stopped,
        ]);

        $this->artisan('storefront:reconcile')
            ->expectsOutput('2 storefront deployment(s) queued for reconciliation.')
            ->assertSuccessful();

        foreach ([$running, $stopped] as $deployment) {
            Queue::assertPushed(
                ReconcileStorefrontJob::class,
                fn(ReconcileStorefrontJob $job): bool => $job->deploymentId === $deployment->id,
            );
        }

        Queue::assertNotPushed(ProvisionStorefrontJob::class);
    });

    it('queues a forced rebuild only when redeployment is asked for by name', function (): void {
        $deployment = reconcilable();

        $this->artisan('storefront:redeploy')
            ->expectsOutput('1 storefront deployment(s) queued for redeployment.')
            ->assertSuccessful();

        Queue::assertPushed(
            ProvisionStorefrontJob::class,
            fn(ProvisionStorefrontJob $job): bool => $job->deploymentId === $deployment->id && $job->force,
        );
        Queue::assertNotPushed(ReconcileStorefrontJob::class);
    });

    it('leaves a deliberately stopped storefront out of a redeployment', function (): void {
        reconcilable(['desired_state' => StorefrontDesiredState::Stopped]);

        $this->artisan('storefront:redeploy')
            ->expectsOutput('0 storefront deployment(s) queued for redeployment.')
            ->assertSuccessful();

        Queue::assertNotPushed(ProvisionStorefrontJob::class);
    });
});

it('reports what a synchronous pass actually changed', function (): void {
    fakeExistingStorefront();
    reconcilable();

    $this->artisan('storefront:reconcile --sync')
        ->expectsOutput('1 storefront deployment(s) reconciled.')
        ->expectsOutput('  in sync: 1')
        ->assertSuccessful();
});
