<?php

declare(strict_types=1);

use Misaf\VendraProperty\Support\PropertyQuota;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSubscription\Enums\PeriodUnit;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

function resellerWithPlan(int $maxUnits, int $existingProperties = 0): Reseller
{
    $reseller = Reseller::factory()->create();

    Subscription::factory()
        ->forSubscriber($reseller)
        ->for(Plan::factory()->maxUnits($maxUnits))
        ->create();

    for ($index = 0; $index < $existingProperties; $index++) {
        createTestTenant(['reseller_id' => $reseller->getKey()]);
    }

    return $reseller;
}

it('allows creating a property below the plan limit', function (): void {
    $reseller = resellerWithPlan(maxUnits: 2, existingProperties: 1);
    $quota = app(PropertyQuota::class);

    expect($quota->canCreateProperty($reseller))->toBeTrue()
        ->and($quota->remainingProperties($reseller))->toBe(1);

    $quota->assertCanCreateProperty($reseller);
});

it('blocks creating a property at the plan limit', function (): void {
    $reseller = resellerWithPlan(maxUnits: 1, existingProperties: 1);
    $quota = app(PropertyQuota::class);

    expect($quota->canCreateProperty($reseller))->toBeFalse()
        ->and($quota->remainingProperties($reseller))->toBe(0);

    $quota->assertCanCreateProperty($reseller);
})->throws(SubscriptionLimitException::class);

it('blocks property creation when no subscription is active', function (): void {
    $reseller = Reseller::factory()->create();
    Subscription::factory()->expired()->forSubscriber($reseller)->for(Plan::factory()->maxUnits(5))->create();

    $quota = app(PropertyQuota::class);

    expect($quota->canCreateProperty($reseller))->toBeFalse()
        ->and($quota->remainingProperties($reseller))->toBe(0);

    $quota->assertCanCreateProperty($reseller);
})->throws(SubscriptionLimitException::class);

it('blocks property creation for an inactive reseller with an active subscription', function (): void {
    $reseller = resellerWithPlan(maxUnits: 2);
    $reseller->update(['active' => false]);

    $quota = app(PropertyQuota::class);

    expect($quota->canCreateProperty($reseller))->toBeFalse()
        ->and($quota->remainingProperties($reseller))->toBe(0);

    $quota->assertCanCreateProperty($reseller);
})->throws(SubscriptionLimitException::class, 'is inactive');

it('creates a reseller subscribed to a plan for its period', function (): void {
    $plan = Plan::factory()->period(PeriodUnit::Month, 1)->create();

    $result = app(CreateResellerAction::class)->execute(
        plan: $plan,
        username: 'acme_owner',
        email: 'owner@acme.test',
        password: 'Secure123',
    );

    expect($result['reseller'])->toBeInstanceOf(Reseller::class)
        ->and($result['reseller']->exists)->toBeTrue()
        ->and($result['owner'])->toBeInstanceOf(ResellerUser::class)
        ->and($result['owner']->reseller_id)->toBe($result['reseller']->getKey())
        ->and($result['subscription']->status)->toBe(SubscriptionStatus::Active)
        ->and($result['subscription']->plan_id)->toBe($plan->getKey())
        ->and($result['subscription']->ends_at->toDateString())
        ->toBe($result['subscription']->starts_at->copy()->addMonth()->toDateString())
        ->and($result['reseller']->activeSubscription()?->getKey())->toBe($result['subscription']->getKey());
});

it('creates a reseller with the requested active state', function (): void {
    $result = app(CreateResellerAction::class)->execute(
        plan: Plan::factory()->create(),
        username: 'paused_owner',
        email: 'owner@paused.test',
        password: 'Secure123',
        active: false,
    );

    expect($result['reseller']->active)->toBeFalse();
});
