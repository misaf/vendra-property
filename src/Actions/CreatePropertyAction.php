<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Misaf\VendraProperty\Support\PropertyQuota;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraTenant\Enums\TenantProvisioningStatus;
use Misaf\VendraTenant\Models\Tenant;
use Misaf\VendraTenant\Models\TenantDomain;
use Misaf\VendraUser\Actions\CreateUserAction;
use Misaf\VendraUser\Models\User;

/**
 * Creates one property, its first domain, and its owner user in a single
 * transaction.
 *
 * A property is either created directly from the console with no billing
 * reseller, or by a reseller that owns it. Which panel the request came from is
 * not recorded, because it is not business state.
 */
final class CreatePropertyAction
{
    public function __construct(
        private readonly CreateUserAction $createUserAction,
        private readonly PropertyQuota $propertyQuota,
    ) {}

    /**
     * @return array{tenant: Tenant, user: User}
     */
    public function execute(
        string $name,
        string $domain,
        string $username,
        string $email,
        string $password,
        ?Reseller $reseller = null,
        bool $shouldSeed = false,
    ): array {
        $domain = TenantDomain::normalizeDomain($domain);
        Validator::make(
            ['domain' => $domain],
            ['domain' => TenantDomain::activeDomainRules()],
        )->validate();

        return DB::transaction(function () use (
            $name,
            $domain,
            $username,
            $email,
            $password,
            $reseller,
            $shouldSeed,
        ): array {
            $lockedReseller = null;

            if (null !== $reseller) {
                /*
                 | Re-read the reseller under a row lock before counting: two
                 | concurrent creations would otherwise both see the last free
                 | slot in the plan and both take it.
                 */
                $lockedReseller = Reseller::query()->lockForUpdate()->findOrFail($reseller->getKey());

                $this->propertyQuota->assertCanCreateProperty($lockedReseller);
            }

            $createdTenant = Tenant::query()->create([
                'reseller_id'              => $lockedReseller?->getKey(),
                'name'                     => $name,
                'slug'                     => $name,
                'active'                   => false,
                'provisioning_status'      => TenantProvisioningStatus::Pending,
                'provisioning_should_seed' => $shouldSeed,
            ]);

            $createdTenant->execute(fn() => $createdTenant->tenantDomains()->create([
                'name'   => $domain,
                'slug'   => $domain,
                'active' => true,
            ]));

            $createdUser = $this->createUserAction->execute(
                tenant: $createdTenant,
                username: $username,
                email: $email,
                password: $password,
                isVerified: true,
            );

            $createdUser->tenants()->syncWithoutDetaching([$createdTenant->getKey()]);

            return [
                'tenant' => $createdTenant,
                'user'   => $createdUser,
            ];
        }, attempts: 5);
    }
}
