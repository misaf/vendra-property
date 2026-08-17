<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Misaf\VendraContainer\Contracts\ContainerRuntime;
use Misaf\VendraContainer\Testing\FakeContainerRuntime;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Models\StorefrontDeployment;

beforeEach(function (): void {
    Config::set('vendra-container.endpoint', 'unix:///var/run/docker.sock');
});

function storefrontStatusRuntime(): FakeContainerRuntime
{
    $runtime = new FakeContainerRuntime();

    app()->instance(ContainerRuntime::class, $runtime);

    return $runtime;
}

it('reports only what the database recorded by default', function (): void {
    StorefrontDeployment::factory()->create(['slug' => 'acme-flowers']);

    $runtime = storefrontStatusRuntime();

    $this->artisan('storefront:status')->assertSuccessful();

    expect($runtime->calls)->toBeEmpty();
});

it('asks the runtime what it actually has when told to', function (): void {
    StorefrontDeployment::factory()->create([
        'slug'           => 'acme-flowers',
        'status'         => StorefrontDeploymentStatus::Ready,
        'desired_state'  => StorefrontDesiredState::Running,
        'container_name' => 'vendra-storefront-acme-flowers',
    ]);

    storefrontStatusRuntime()->withRunningContainer('vendra-storefront-acme-flowers');

    $this->artisan('storefront:status --runtime')
        ->doesntExpectOutputToContain('The runtime has no container for')
        ->assertSuccessful();
});

/*
 | Changing CONTAINER_RUNTIME or CONTAINER_ENDPOINT leaves the previous daemon's
 | containers running, unmanaged and invisible, while every deployment row still
 | reads as ready. Absent-but-wanted is the only symptom available, so it is
 | reported as a failure rather than printed in a column and left to be noticed.
 */
it('reports storefronts the runtime has nothing for', function (): void {
    StorefrontDeployment::factory()->create([
        'slug'           => 'acme-flowers',
        'status'         => StorefrontDeploymentStatus::Ready,
        'desired_state'  => StorefrontDesiredState::Running,
        'container_name' => 'vendra-storefront-acme-flowers',
    ]);

    storefrontStatusRuntime();

    $this->artisan('storefront:status --runtime')
        ->expectsOutputToContain('The runtime has no container for: acme-flowers')
        ->assertFailed();
});

it('does not report a storefront that is absent because it is meant to be', function (): void {
    StorefrontDeployment::factory()->create([
        'slug'          => 'acme-flowers',
        'status'        => StorefrontDeploymentStatus::Ready,
        'desired_state' => StorefrontDesiredState::Stopped,
    ]);

    storefrontStatusRuntime();

    $this->artisan('storefront:status --runtime')
        ->doesntExpectOutputToContain('The runtime has no container for')
        ->assertSuccessful();
});

it('still prints the recorded state when the runtime will not answer', function (): void {
    StorefrontDeployment::factory()->create(['slug' => 'acme-flowers']);

    storefrontStatusRuntime()->unreachable();

    $this->artisan('storefront:status --runtime')
        ->expectsOutputToContain('acme-flowers')
        ->assertSuccessful();
});
