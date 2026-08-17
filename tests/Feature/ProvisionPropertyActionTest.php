<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraProperty\Actions\ProvisionPropertyAction;
use Misaf\VendraProperty\Jobs\CompletePropertyProvisioningJob;
use Misaf\VendraSupport\Tenancy\Events\TenantProvisioned;
use Misaf\VendraTenant\Enums\TenantProvisioningStatus;

beforeEach(function (): void {
    Event::fake([TenantProvisioned::class]);
    Queue::fake();
});

it('hashes a provided owner password', function (): void {
    $result = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'Acme',
        'domain'   => 'acme.test',
        'username' => 'admin_acme',
        'email'    => 'admin@acme.test',
    ], password: 'secret-password');

    expect($result['password'])->toBe('secret-password')
        ->and(Hash::check('secret-password', $result['user']->password))->toBeTrue();
});

it('generates a random owner password when none is provided', function (): void {
    $result = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'Acme',
        'domain'   => 'acme.test',
        'username' => 'admin_acme',
        'email'    => 'admin@acme.test',
    ]);

    expect($result['password'])->toHaveLength(8)
        ->and(Hash::check($result['password'], $result['user']->password))->toBeTrue();
});

it('assigns the owner role for the user guard when another guard is active', function (): void {
    Config::set('auth.defaults.guard', 'console');

    $result = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'Acme',
        'domain'   => 'acme.test',
        'username' => 'admin_acme',
        'email'    => 'admin@acme.test',
    ]);

    expect($result['user']->roles)->toHaveCount(1)
        ->and($result['user']->roles->sole()->guard_name)->toBe('web');
});

it('stamps the domain with the newly provisioned tenant even when another tenant is current', function (): void {
    $first = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'First',
        'domain'   => 'first.test',
        'username' => 'admin_first',
        'email'    => 'admin@first.test',
    ]);

    switchToTestTenant($first['tenant']);

    $second = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'Second',
        'domain'   => 'second.test',
        'username' => 'admin_second',
        'email'    => 'admin@second.test',
    ]);

    $domain = $second['tenant']->execute(
        fn() => $second['tenant']->tenantDomains()->first(),
    );

    expect($domain)->not->toBeNull()
        ->and($domain->name)->toBe('second.test')
        ->and($domain->tenant_id)->toBe($second['tenant']->getKey());
});

it('queues durable tenant provisioning after creating inactive records', function (): void {
    $result = app(ProvisionPropertyAction::class)->execute([
        'name'     => 'Acme',
        'domain'   => 'acme.test',
        'username' => 'admin_acme',
        'email'    => 'admin@acme.test',
    ], shouldSeed: true);

    expect($result['tenant']->active)->toBeFalse()
        ->and($result['tenant']->provisioning_status)->toBe(TenantProvisioningStatus::Pending)
        ->and($result['tenant']->provisioning_should_seed)->toBeTrue();

    Queue::assertPushed(
        CompletePropertyProvisioningJob::class,
        fn(CompletePropertyProvisioningJob $job): bool => $job->tenantId === $result['tenant']->id,
    );
    Event::assertNotDispatched(TenantProvisioned::class);
});
