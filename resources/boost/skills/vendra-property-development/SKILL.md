---
name: vendra-property-development
description: "Create, modify, review, or test the Vendra Property module in packages/vendra-property, changing the property domain and the storefront deployment lifecycle. Use for StorefrontProvisioner, ContainerStorefrontProvisioner, StorefrontDeployment, StorefrontDeploymentStatus, StorefrontDesiredState, StorefrontRuntimeState, StorefrontReconciliationOutcome, CreatePropertyAction, ProvisionPropertyAction, DeletePropertyAction, DeployPropertyStorefrontAction, DestroyPropertyStorefrontAction, ControlPropertyStorefrontAction, ReconcilePropertyStorefrontAction, RequestStorefrontDeploymentAction, ProvisionStorefrontJob, DestroyStorefrontJob, ReconcileStorefrontJob, CompletePropertyProvisioningJob, StorefrontSettings, StorefrontConfigurationMap, StorefrontConfigurationValidator, StorefrontContainerDefinitionFactory, StorefrontOrigins, PropertyQuota, CreatePropertyPage, and the storefront console commands."
---

# Vendra Property

## Workflow

- Inspect `composer.json`, sibling files, and existing tests before changing the package.
- Use Laravel Boost `application-info` and `search-docs` before code changes.
- Apply `laravel-best-practices` to Laravel PHP and `pest-testing` whenever tests change.
- Keep changes inside this package's boundary and preserve its public contracts.
- Add or update focused Pest coverage, then run `php artisan test --compact --testsuite=vendra-property` from the project root.

## Translatable Persistence

- Making a persisted model field translatable is an explicit domain choice unless this package already requires it.
- Every field listed in a model's `$translatable` array must definitely use a JSON database column. Keep its model traits/casts, factories, validation, Filament locale UI, API serialization, and tests translation-aware.
- A field not listed in `$translatable` must use the appropriate scalar database type and must not use Spatie Translatable, translatable slug traits, locale switchers, translated callbacks, or translation-shaped array data.

## Vendra Transitive API Policy

- Treat a Vendra dependency intentionally exposed through the public API of a directly required Vendra platform package as part of the supported public contract of that package.
- Do not add a redundant direct Composer requirement solely because source code imports a type from that exposed dependency.
- Apply this only to Vendra platform packages listed under `require`; never extend it to `require-dev`, `suggest`, incidental implementation dependencies, or third-party packages. Removing or replacing an exposed dependency is a breaking change; keep `self.version` alignment across the Vendra package graph.

## Module Boundary

- This package owns *what* a storefront should be and *when* it should change. It never owns *how* a runtime is driven — that lives behind `Contracts\StorefrontProvisioner`.
- It depends on `misaf/vendra-container` for the runtime and on `misaf/vendra-reseller` for the billing owner. It is deliberately coupled to the concrete `Reseller` model; do not introduce a `PropertyOwner` abstraction to "clean up" the arrow.
- Panels belong to their own packages. Put shared schemas, page bases, actions, and concerns here; put the panel-specific resource in `vendra-console` or `vendra-reseller`.

## Provisioner Contract

- `provision`, `start`, `stop`, `restart`, `destroy`, `observe`, `logs` — typed value objects on both sides, never arrays.
- Idempotence is part of the contract: provisioning twice leaves one storefront; starting a running one, stopping a stopped one, and destroying an absent one all succeed.
- `observe()` returns a `StorefrontObservation` rich enough to tell "stopped" from "running the wrong image", and must never answer "absent" for an unreachable runtime.
- `ContainerStorefrontProvisioner` builds its container through `Support\StorefrontContainerDefinitionFactory` after `Support\StorefrontConfigurationValidator` accepts the configuration. Anything Docker-shaped stops inside `misaf/vendra-container`.

## Deployment Lifecycle

- `RequestStorefrontDeploymentAction` → `ProvisionStorefrontJob` → `StorefrontDeployment`. Reconciliation and retry go through `StorefrontDeploymentDispatchCommand`; do not add a second dispatch path.
- Write status only via `markProcessing()`, `markReady()`, `markRequested()`, `markFailed()`. `Enums\StorefrontDeploymentStatus::transitions()` is the transition table and `InvalidStorefrontTransitionException` is the rejection.
- A failing attempt with retries left stays `Processing`; only `ProvisionStorefrontJob::failed()` writes `Failed`.
- `ProvisionStorefrontJob` runs on its own `storefronts` queue, served by the single worker that holds a runtime socket. Do not move it onto a shared queue.

## Configuration

- Read `config/vendra-property.php` only through the injected `Support\StorefrontSettings`, including the "can we provision?" check. Keep it bound with `bind()` so config changes are picked up on the next resolve.
- Runtime differences (Docker vs. a Podman compatibility socket, log driver, health-check behaviour) are configuration, never a branch. Do not add runtime sniffing.

## Testing

- Use `Misaf\VendraContainer\Testing\FakeContainerRuntime` rather than a real daemon; domain tests must not require Docker or Podman.
- Cover each lifecycle transition, including the illegal ones the enum rejects, and the retry path that must not mark a deployment `Failed` early.

## Filament

- Resources with a cluster live in `src/Filament/Clusters/Resources/`; resources without one live in `src/Filament/Resources/`. This package currently ships pages, schemas, actions, and concerns rather than resources.
