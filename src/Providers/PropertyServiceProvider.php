<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Providers;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Misaf\VendraProperty\Console\Commands\ReconcileStorefrontDeploymentsCommand;
use Misaf\VendraProperty\Console\Commands\RedeployStorefrontsCommand;
use Misaf\VendraProperty\Console\Commands\RetryFailedStorefrontDeploymentsCommand;
use Misaf\VendraProperty\Console\Commands\StorefrontDeploymentStatusCommand;
use Misaf\VendraProperty\Console\Commands\StorefrontLifecycleCommand;
use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Observers\TenantDomainObserver;
use Misaf\VendraProperty\Services\ContainerStorefrontProvisioner;
use Misaf\VendraProperty\Support\StorefrontSettings;
use Misaf\VendraSupport\Tenancy\TenantTableRegistry;
use Misaf\VendraTenant\Models\TenantDomain;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class PropertyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vendra-property')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations([
                'create_storefront_deployments_table',
            ])
            ->hasCommands(
                ReconcileStorefrontDeploymentsCommand::class,
                RedeployStorefrontsCommand::class,
                RetryFailedStorefrontDeploymentsCommand::class,
                StorefrontDeploymentStatusCommand::class,
                StorefrontLifecycleCommand::class,
            )
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->publishConfigFile()->askToStarRepoOnGitHub('misaf/vendra-property');
            });
    }

    public function packageRegistered(): void
    {
        /*
         | Bound rather than shared, so a configuration change — a test setting
         | an image, an operator reloading config — is picked up on the next
         | resolve rather than frozen at first use.
         */
        $this->app->bind(StorefrontSettings::class, static fn(): StorefrontSettings => StorefrontSettings::fromConfig());

        // One container per storefront, through whichever runtime vendra-container binds.
        $this->app->bind(StorefrontProvisioner::class, ContainerStorefrontProvisioner::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(TenantTableRegistry::class)->register('storefront_deployments');

        TenantDomain::observe(TenantDomainObserver::class);

        AboutCommand::add('Vendra Property', fn(): array => [
            'Version' => InstalledVersions::getPrettyVersion('misaf/vendra-property'),
        ]);
    }
}
