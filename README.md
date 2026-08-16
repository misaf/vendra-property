# Vendra Property

The property domain and the storefront lifecycle for Laravel. A property is a
tenant with a domain, an owner, and one storefront; this package decides *when*
that storefront should be deployed, started, stopped, or destroyed and *what* it
should contain.

How a runtime is made to agree is somebody else's problem. Everything crossing
that line goes through one typed port, `StorefrontProvisioner`, whose interface
never mentions containers — the shipped implementation happens to use them.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament 5
- `misaf/vendra-container` for the shipped provisioner, plus `misaf/vendra-tenant`,
  `misaf/vendra-reseller`, `misaf/vendra-subscription`, `misaf/vendra-permission`
  and `misaf/vendra-support`

## Installation

```bash
composer require misaf/vendra-property
php artisan vendor:publish --tag=vendra-property-config
php artisan migrate
```

The package ships the `storefront_deployments` migration; the `tenants` table it
references belongs to `misaf/vendra-tenant`.

## Configuration

`config/vendra-property.php` describes what a storefront *is*. The runtime
endpoint and API version are not here — they belong to `misaf/vendra-container`.

```dotenv
STOREFRONT_IMAGE=ghcr.io/misaf/vendra-storefront-florist@sha256:…
STOREFRONT_THEMES=default
STOREFRONT_NETWORK=traefik-public
STOREFRONT_NAME_PREFIX=vendra-storefront-
STOREFRONT_PORT=3000
STOREFRONT_HEALTH_PATH=/api/health
STOREFRONT_HEALTH_TIMEOUT=120
STOREFRONT_PULL=true
STOREFRONT_BASE_DOMAIN=
STOREFRONT_API_URL=
STOREFRONT_CERT_RESOLVER=
```

The platform does not create the network. The network, the reverse proxy, and
the TLS material belong to whoever runs the estate; deployment fails with a
pointed error when the network is absent rather than inventing one the proxy is
not attached to.

Every setting is read through the injected `Support\StorefrontSettings` value
object, including the "can we provision at all?" check. It is bound with
`bind()`, so a configuration change is picked up on the next resolve.

## Usage

### Provisioning a property

```php
use Misaf\VendraProperty\Actions\ProvisionPropertyAction;

['tenant' => $tenant, 'user' => $user, 'password' => $password] = app(ProvisionPropertyAction::class)
    ->execute(
        data: ['domain' => 'flowers-a.com', 'email' => 'owner@flowers-a.com'],
        shouldSeed: true,
        reseller: $reseller,
    );
```

The console and the reseller panel both call this and differ only in which
reseller they resolve, so the flow exists once. It creates the tenant, the owner
user, and the administrator role, then queues the work that finishes
provisioning.

### Deploying a storefront

```php
use Misaf\VendraProperty\Actions\RequestStorefrontDeploymentAction;

$deployment = app(RequestStorefrontDeploymentAction::class)->execute($tenant, 'flowers-a.com', $form);
```

`RequestStorefrontDeploymentAction` → `ProvisionStorefrontJob` → `StorefrontDeployment`
is the only path. The job runs on its own `storefronts` queue
(`ProvisionStorefrontJob::QUEUE`), served by the single worker that holds a
runtime socket.

Status is written only through the model's `markProcessing()`, `markReady()`,
`markRequested()` and `markFailed()`, which enforce the
`Enums\StorefrontDeploymentStatus` transition table and throw
`InvalidStorefrontTransitionException` otherwise. A job attempt that throws with
retries left stays `Processing`; only `ProvisionStorefrontJob::failed()` writes
`Failed`.

### The provisioner port

| Method | Purpose |
| --- | --- |
| `provision(StorefrontProvisionRequest)` | Places the storefront, replacing any predecessor. |
| `start` / `stop` / `restart` | Lifecycle, by `StorefrontReference`. |
| `destroy(StorefrontReference)` | Removes it entirely; absent is success. |
| `observe(StorefrontReference)` | A `StorefrontObservation` of what is actually running. |
| `logs(StorefrontReference, int $lines = 200)` | Recent output, for diagnosing a failure. |

Idempotence is contractual: provisioning twice leaves one storefront, starting a
running one succeeds, stopping a stopped one succeeds. Reconciliation and retry
depend on it. `observe()` must never answer "absent" for a runtime it could not
reach — a converge pass would read that as a missing storefront and rebuild a
healthy one.

`Services\ContainerStorefrontProvisioner` is the one implementation, bound in
`PropertyServiceProvider`. Callers type-hint the contract and never learn which
adapter answered.

## Commands

```bash
php artisan storefront:status [--runtime]     # deployments from the database
php artisan storefront:reconcile [--sync]     # converge every runtime with intent
php artisan storefront:redeploy [--sync]      # rebuild everything meant to be up
php artisan storefront:retry-failed [--sync]  # retry deployments marked failed
php artisan storefront:lifecycle {start|stop|restart|status|logs} {slug}
```

`storefront:reconcile` is cheap and safe to repeat: it corrects with the
narrowest verb that works, so a converged estate comes through a pass untouched.
Reach for `storefront:redeploy` only for a change convergence cannot see — an
image republished under the same reference, or an edge label that only a fresh
container will carry. `storefront:lifecycle` records intent, so a storefront
stopped there stays stopped through the next pass.

## Filament

This package ships the shared building blocks — `Filament\Pages\CreatePropertyPage`,
`Filament\Schemas\StorefrontConfigurationFields`, `Filament\Actions\ReplaceDomainAction`,
`Filament\Concerns\BuildsDailyTrend` — rather than panel resources. The console
and reseller panels own those and differ only in which reseller they resolve as
the property's billing owner.

## Testing

Use `Misaf\VendraContainer\Testing\FakeContainerRuntime`; no test here should
need a real daemon.

```bash
php artisan test --compact --testsuite=vendra-property
```

## License

MIT. See [LICENSE](LICENSE).
