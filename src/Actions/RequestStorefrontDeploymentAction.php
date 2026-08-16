<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Misaf\VendraContainer\Support\ContainerRuntimeConfiguration;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Jobs\ProvisionStorefrontJob;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraProperty\Support\StorefrontConfigurationMap;
use Misaf\VendraProperty\Support\StorefrontConfigurationValidator;
use Misaf\VendraTenant\Models\Tenant;

/**
 * Records that a property should have a storefront, and asks for it to be built.
 *
 * The row is written whether or not a container runtime is configured: a
 * deployment nobody can act on yet is still the property's intent, and
 * reconciliation picks it up once the estate is up. Dispatching a job certain to
 * fail would only fill the failed-jobs table instead.
 */
final class RequestStorefrontDeploymentAction
{
    public function __construct(private readonly ContainerRuntimeConfiguration $runtime) {}

    /**
     * @param array<string, mixed> $form
     *
     * @throws ValidationException
     */
    public function execute(Tenant $tenant, string $domain, array $form): StorefrontDeployment
    {
        $configuration = StorefrontConfigurationMap::toConfiguration($form);

        /*
         | Validate here rather than leaving it to the provisioner. The image
         | refuses to boot on an incomplete configuration, so a field missed at
         | this point used to surface minutes later as a failed deployment and a
         | crash-looping container instead of as an error on the form.
         */
        Validator::make($configuration, StorefrontConfigurationValidator::deploymentRules())->validate();

        $deployment = StorefrontDeployment::query()->create([
            'tenant_id'     => $tenant->id,
            'slug'          => Arr::string($form, 'storefront_slug'),
            'domain'        => $domain,
            'theme'         => Arr::string($form, 'storefront_theme'),
            'configuration' => $configuration,
            'status'        => StorefrontDeploymentStatus::Pending,
            'desired_state' => StorefrontDesiredState::Running,
        ]);

        if ($this->runtime->isConfigured()) {
            ProvisionStorefrontJob::dispatch($deployment->id)->afterCommit();
        }

        return $deployment;
    }
}
