<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraProperty\Actions\RequestStorefrontDeploymentAction;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Jobs\ProvisionStorefrontJob;
use Misaf\VendraProperty\Jobs\ReconcileStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;

beforeEach(function (): void {
    Queue::fake();

    // Container provisioning itself has its own file; this one covers the
    // configuration the platform builds and the commands that queue it.
    Config::set('vendra-container.endpoint', 'unix:///var/run/docker.sock');
});

it('keeps an unconfigured deployment pending instead of pretending it succeeded', function (): void {
    Config::set('vendra-container.endpoint', '');
    $tenant = createTestTenant();

    $deployment = app(RequestStorefrontDeploymentAction::class)->execute(
        $tenant,
        'acme.test',
        storefrontRequestData(),
    );

    expect($deployment->status)->toBe(StorefrontDeploymentStatus::Pending)
        ->and($deployment->configuration['name']['en'])->toBe('Acme Flowers')
        ->and($deployment->configuration['priceCurrency'])->toBe('IRR')
        ->and($deployment->configuration['address']['country'])->toBe('IR');
    Queue::assertNothingPushed();
});

it('queues provisioning when the provider is configured', function (): void {
    $tenant = createTestTenant();

    $deployment = app(RequestStorefrontDeploymentAction::class)->execute(
        $tenant,
        'acme.test',
        storefrontRequestData(),
    );

    Queue::assertPushed(
        ProvisionStorefrontJob::class,
        fn(ProvisionStorefrontJob $job): bool => $job->deploymentId === $deployment->id,
    );
});

it('reconciles every database deployment including ready storefronts', function (): void {
    $deployments = StorefrontDeployment::factory()->count(2)->sequence(
        ['status' => StorefrontDeploymentStatus::Pending],
        ['status' => StorefrontDeploymentStatus::Ready],
    )->create();

    $this->artisan('storefront:reconcile')
        ->expectsOutput('2 storefront deployment(s) queued for reconciliation.')
        ->assertSuccessful();

    foreach ($deployments as $deployment) {
        Queue::assertPushed(
            ReconcileStorefrontJob::class,
            fn(ReconcileStorefrontJob $job): bool => $job->deploymentId === $deployment->id,
        );
    }
});

it('lists the database-backed storefront fleet', function (): void {
    StorefrontDeployment::factory()->create([
        'slug'               => 'beta-flowers',
        'domain'             => 'beta.test',
        'status'             => StorefrontDeploymentStatus::Ready,
        'container_name'     => 'container-beta',
        'image_digest'       => 'sha256:beta',
    ]);
    StorefrontDeployment::factory()->create([
        'slug'   => 'alpha-flowers',
        'domain' => 'alpha.test',
        'status' => StorefrontDeploymentStatus::Pending,
    ]);

    $this->artisan('storefront:status')
        ->expectsTable(
            ['Slug', 'Domain', 'Status', 'Desired', 'Container', 'Image digest'],
            [
                ['alpha-flowers', 'alpha.test', 'pending', 'running', '—', '—'],
                ['beta-flowers', 'beta.test', 'ready', 'running', 'container-beta', 'sha256:beta'],
            ],
        )
        ->assertSuccessful();
});

it('retries only failed storefront deployments', function (): void {
    $failedDeployment = StorefrontDeployment::factory()->create([
        'status' => StorefrontDeploymentStatus::Failed,
    ]);
    $readyDeployment = StorefrontDeployment::factory()->create([
        'status' => StorefrontDeploymentStatus::Ready,
    ]);

    $this->artisan('storefront:retry-failed')
        ->expectsOutput('1 failed storefront deployment(s) queued for retry.')
        ->assertSuccessful();

    Queue::assertPushed(
        ProvisionStorefrontJob::class,
        fn(ProvisionStorefrontJob $job): bool => $job->deploymentId === $failedDeployment->id,
    );
    Queue::assertNotPushed(
        ProvisionStorefrontJob::class,
        fn(ProvisionStorefrontJob $job): bool => $job->deploymentId === $readyDeployment->id,
    );
});

it('carries per-locale message overrides into the encoded configuration', function (): void {
    $tenant = createTestTenant();

    $deployment = app(RequestStorefrontDeploymentAction::class)->execute(
        $tenant,
        'acme.test',
        [...storefrontRequestData(), 'storefront_messages' => [
            'en' => ['products' => ['title' => 'Our Breads']],
            'fa' => ['products' => ['title' => 'نان‌های ما']],
        ]],
    );

    expect($deployment->configuration['messages']['en']['products']['title'])->toBe('Our Breads')
        ->and($deployment->configuration['messages']['fa']['products']['title'])->toBe('نان‌های ما');
});

it('omits messages entirely when none are supplied', function (): void {
    Config::set('vendra-container.endpoint', '');
    $tenant = createTestTenant();

    $deployment = app(RequestStorefrontDeploymentAction::class)->execute($tenant, 'acme.test', storefrontRequestData());

    expect($deployment->configuration)->not->toHaveKey('messages');
});

it('drops malformed message overrides rather than shipping a configuration the storefront rejects', function (): void {
    Config::set('vendra-container.endpoint', '');
    $tenant = createTestTenant();

    $deployment = app(RequestStorefrontDeploymentAction::class)->execute(
        $tenant,
        'acme.test',
        [...storefrontRequestData(), 'storefront_messages' => [
            'en'  => ['products' => ['title' => 'Kept']],
            'bad' => 'not-an-array',
            'fa'  => [],
        ]],
    );

    expect($deployment->configuration['messages'])->toBe(['en' => ['products' => ['title' => 'Kept']]]);
});
