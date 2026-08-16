<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraSupport\Tenancy\Events\TenantProvisioned;
use Misaf\VendraTenant\Enums\TenantProvisioningStatus;
use Misaf\VendraTenant\Jobs\CacheTenantRoutesJob;
use Misaf\VendraTenant\Models\Tenant;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * Finishes provisioning one property off the request lifecycle: seeding, route
 * caching, and the switch to active.
 *
 * It is the application-orchestration half of property creation, so it lives
 * beside the domain rather than inside it — the action decides a property should
 * exist, this makes the slow parts happen and records whether they did.
 */
final class CompletePropertyProvisioningJob implements NotTenantAware, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $tenantId) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $this->context($tenant)->scope(fn() => $this->provision($tenant));
    }

    private function provision(Tenant $tenant): void
    {
        if (TenantProvisioningStatus::Ready === $tenant->provisioning_status) {
            return;
        }

        $tenant->forceFill([
            'active'                 => false,
            'provisioning_status'    => TenantProvisioningStatus::Processing,
            'provisioning_failed_at' => null,
            'provisioning_error'     => null,
        ])->save();

        try {
            if ($tenant->provisioning_should_seed && null === $tenant->provisioning_seeded_at) {
                event(new TenantProvisioned($tenant, shouldSeed: true));

                $tenant->forceFill(['provisioning_seeded_at' => now()])->save();
            }

            if (null === $tenant->routes_cached_at) {
                CacheTenantRoutesJob::dispatchSync($tenant->id);

                $tenant->forceFill(['routes_cached_at' => now()])->save();
            }

            $billingSuspendedAt = $this->shouldStartBillingSuspended($tenant)
                ? now()
                : null;

            $tenant->forceFill([
                'active'                 => true,
                'billing_suspended_at'   => $billingSuspendedAt,
                'provisioning_status'    => TenantProvisioningStatus::Ready,
                'provisioned_at'         => now(),
                'provisioning_failed_at' => null,
                'provisioning_error'     => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        $this->context($tenant)->scope(fn() => $this->markFailed($exception));
    }

    /**
     * A property whose billing reseller is not paying starts suspended.
     *
     * A property created straight from the console has no reseller, so there is
     * nobody to suspend for and it starts active.
     */
    private function shouldStartBillingSuspended(Tenant $tenant): bool
    {
        if (null === $tenant->reseller_id) {
            return false;
        }

        $reseller = Reseller::query()->find($tenant->reseller_id);

        return null === $reseller
            || ! $reseller->isSubscriptionActive()
            || null === $reseller->activeSubscription();
    }

    private function markFailed(?Throwable $exception): void
    {
        Tenant::query()
            ->whereKey($this->tenantId)
            ->where('provisioning_status', '!=', TenantProvisioningStatus::Ready->value)
            ->update([
                'active'                 => false,
                'provisioning_status'    => TenantProvisioningStatus::Failed->value,
                'provisioning_failed_at' => now(),
                'provisioning_error'     => null === $exception
                    ? 'Property provisioning failed.'
                    : Str::limit($exception->getMessage(), 2000, ''),
            ]);
    }

    private function context(?Tenant $tenant): RequestJobContext
    {
        return new RequestJobContext(
            traceId: RequestJobContext::resolveTraceId(),
            operation: 'property_provisioning',
            tenantId: $this->tenantId,
            metadata: [ContextKeys::RESELLER_ID => $tenant?->reseller_id],
        );
    }
}
