<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Misaf\VendraProperty\Database\Factories\StorefrontDeploymentFactory;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Exceptions\InvalidStorefrontTransitionException;
use Misaf\VendraTenant\Models\Tenant;

/**
 * The storefront one property owns: its configuration, and the state of the
 * workload the platform runs for it.
 *
 * `desired_state` is what the platform intends; `status`, `container_name` and
 * `image_digest` are what it last observed. Keeping both is what lets a stopped
 * storefront stay stopped through a reconciliation pass instead of being started
 * again on the assumption that stopped means broken.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $slug
 * @property string $domain
 * @property string $theme
 * @property array<string, mixed> $configuration
 * @property StorefrontDeploymentStatus $status
 * @property StorefrontDesiredState $desired_state
 * @property string|null $container_name
 * @property string|null $image
 * @property string|null $image_digest
 * @property Carbon|null $requested_at
 * @property Carbon|null $deployed_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id', 'slug', 'domain', 'theme', 'configuration', 'status', 'desired_state',
    'container_name', 'image', 'image_digest', 'requested_at', 'deployed_at',
    'failed_at', 'error',
])]
#[UseFactory(StorefrontDeploymentFactory::class)]
final class StorefrontDeployment extends Model
{
    /** @use HasFactory<StorefrontDeploymentFactory> */
    use HasFactory;

    /**
     * The property this storefront belongs to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Deployments the platform intends to have running.
     *
     * @param  Builder<StorefrontDeployment>  $query
     * @return Builder<StorefrontDeployment>
     */
    public function scopeDesiredRunning(Builder $query): Builder
    {
        return $query->where('desired_state', StorefrontDesiredState::Running->value);
    }

    /**
     * Enter provisioning, clearing any previous failure.
     */
    public function markProcessing(): void
    {
        $this->transitionTo(StorefrontDeploymentStatus::Processing, [
            'failed_at' => null,
            'error'     => null,
        ]);
    }

    /**
     * The storefront is placed and passed its health gate.
     */
    public function markReady(?string $containerName, ?string $image, ?string $imageDigest): void
    {
        $this->transitionTo(StorefrontDeploymentStatus::Ready, [
            'container_name' => $containerName,
            'image'          => $image,
            'image_digest'   => $imageDigest,
            'requested_at'   => now(),
            'deployed_at'    => now(),
        ]);
    }

    /**
     * The storefront is placed but unproven — reconciliation revisits it.
     */
    public function markRequested(?string $containerName, ?string $image, ?string $imageDigest): void
    {
        $this->transitionTo(StorefrontDeploymentStatus::Requested, [
            'container_name' => $containerName,
            'image'          => $image,
            'image_digest'   => $imageDigest,
            'requested_at'   => now(),
            'deployed_at'    => null,
        ]);
    }

    /**
     * Provisioning gave up. Written only once the queue has exhausted its
     * attempts, so a deployment that is still retrying never reads as failed.
     */
    public function markFailed(string $error): void
    {
        $this->transitionTo(StorefrontDeploymentStatus::Failed, [
            'failed_at' => now(),
            'error'     => Str::limit($error, 2000, ''),
        ]);
    }

    /**
     * Record the intent behind a lifecycle command.
     *
     * Separate from the status transitions: stopping a storefront does not
     * un-deploy it, and the row must still say what image is placed so it can be
     * started again without a redeploy.
     */
    public function markDesiredState(StorefrontDesiredState $state): void
    {
        $this->forceFill(['desired_state' => $state])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id'     => 'integer',
            'configuration' => 'array',
            'status'        => StorefrontDeploymentStatus::class,
            'desired_state' => StorefrontDesiredState::class,
            'requested_at'  => 'datetime',
            'deployed_at'   => 'datetime',
            'failed_at'     => 'datetime',
        ];
    }

    /**
     * Move to a status the current one allows, writing the attributes that go
     * with it in the same save.
     *
     * @param array<string, mixed> $attributes
     *
     * @throws InvalidStorefrontTransitionException
     */
    private function transitionTo(StorefrontDeploymentStatus $status, array $attributes): void
    {
        if ( ! $this->status->canTransitionTo($status)) {
            throw InvalidStorefrontTransitionException::between($this->status, $status);
        }

        $this->forceFill([...$attributes, 'status' => $status])->save();
    }
}
