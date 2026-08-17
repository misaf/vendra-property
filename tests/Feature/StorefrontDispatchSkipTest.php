<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Jobs\ProvisionStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;

beforeEach(function (): void {
    Config::set('vendra-container.endpoint', 'unix:///var/run/docker.sock');
});

/**
 * Hold the unique lock the way a job already in flight does.
 */
function holdProvisionLockFor(StorefrontDeployment $deployment): void
{
    (new UniqueLock(cache()->driver()))->acquire(new ProvisionStorefrontJob($deployment->id, force: true));
}

it('reports only the deployments the bus actually accepted', function (): void {
    Queue::fake();

    $queued = StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'desired_state' => StorefrontDesiredState::Running,
    ]);
    $inFlight = StorefrontDeployment::factory()->create([
        'slug'          => 'busy-florist',
        'desired_state' => StorefrontDesiredState::Running,
    ]);

    holdProvisionLockFor($inFlight);

    $this->artisan('storefront:redeploy')
        ->expectsOutputToContain('1 storefront deployment(s) queued for redeployment.')
        ->expectsOutputToContain('1 skipped, already being provisioned: busy-florist')
        ->assertSuccessful();

    expect($queued->fresh())->not->toBeNull();
});

/*
 | The run that cost an afternoon: every dispatch discarded, and the command
 | still reporting the whole estate as queued.
 */
it('does not claim to have queued anything when every dispatch was discarded', function (): void {
    Queue::fake();

    $deployment = StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'desired_state' => StorefrontDesiredState::Running,
    ]);

    holdProvisionLockFor($deployment);

    $this->artisan('storefront:redeploy')
        ->expectsOutputToContain('0 storefront deployment(s) queued for redeployment.')
        ->expectsOutputToContain('1 skipped, already being provisioned: acme-flowers')
        ->assertSuccessful();
});

it('says nothing about skipping when every dispatch was accepted', function (): void {
    Queue::fake();

    StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'desired_state' => StorefrontDesiredState::Running,
    ]);

    $this->artisan('storefront:redeploy')
        ->expectsOutputToContain('1 storefront deployment(s) queued for redeployment.')
        ->doesntExpectOutputToContain('skipped')
        ->assertSuccessful();
});

it('breaks a stale lock only when asked to', function (): void {
    Queue::fake();

    $deployment = StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'desired_state' => StorefrontDesiredState::Running,
    ]);

    holdProvisionLockFor($deployment);

    $this->artisan('storefront:redeploy --force-unique')
        ->expectsOutputToContain('1 storefront deployment(s) queued for redeployment.')
        ->doesntExpectOutputToContain('skipped')
        ->assertSuccessful();

    Queue::assertPushed(ProvisionStorefrontJob::class);
});

it('offers the escape hatch on every dispatching command', function (): void {
    foreach (['storefront:redeploy', 'storefront:retry-failed', 'storefront:reconcile'] as $command) {
        expect(Artisan::all()[$command]->getDefinition()->hasOption('force-unique'))
            ->toBeTrue("[{$command}] should accept --force-unique");
    }
});
