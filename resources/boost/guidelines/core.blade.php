## Vendra Property

The `misaf/vendra-property` package owns the **property domain and the storefront lifecycle**. It decides when a property's storefront should be deployed, started, stopped, or destroyed, and what it should contain. It does not decide how a runtime is made to agree — that is an adapter's job.

### Translatable Persistence

- Making a persisted model field translatable is an explicit domain choice unless this package already requires it.
- Every field listed in a model's `$translatable` array must definitely use a JSON database column. Keep its model traits/casts, factories, validation, Filament locale UI, API serialization, and tests translation-aware.
- A field not listed in `$translatable` must use the appropriate scalar database type and must not use Spatie Translatable, translatable slug traits, locale switchers, translated callbacks, or translation-shaped array data.

### Vendra Transitive API Policy

- Treat a Vendra dependency intentionally exposed through the public API of a directly required Vendra platform package as part of the supported public contract of that package.
- Do not add a redundant direct Composer requirement solely because source code imports a type from that exposed dependency.
- Apply this only to Vendra platform packages listed under `require`; never extend it to `require-dev`, `suggest`, incidental implementation dependencies, or third-party packages. Removing or replacing an exposed dependency is a breaking change; keep `self.version` alignment across the Vendra package graph.

- Keep property and storefront code inside `packages/vendra-property` using the `Misaf\VendraProperty` namespace.
- `Contracts\StorefrontProvisioner` is the port to whatever actually runs a storefront. Every value crossing it is typed (`StorefrontProvisionRequest`, `StorefrontProvisionResult`, `StorefrontReference`, `StorefrontObservation`) — do not widen a method to an array parameter or return. Nothing on the interface mentions containers, because an implementation need not use them.
- `Services\ContainerStorefrontProvisioner` is the one implementation today, bound in `PropertyServiceProvider::packageRegistered()`. It reaches the runtime only through `misaf/vendra-container`'s `ContainerRuntime`; callers type-hint the contract and must never branch on which adapter answered.
- Idempotence is contractual: `provision()` twice leaves one storefront, `start()` on a running one succeeds, `stop()` on a stopped one succeeds, `destroy()` on an absent one succeeds. Reconciliation and retry depend on this.
- `observe()` must never report "absent" for a runtime it could not reach — a converge pass would read that as a missing storefront and rebuild a healthy one.
- Status changes go through `StorefrontDeployment`'s `markProcessing()`/`markReady()`/`markRequested()`/`markFailed()`, which enforce the `Enums\StorefrontDeploymentStatus` transition table. Never `forceFill(['status' => ...])`. A job attempt that throws with retries left stays `Processing`; only `ProvisionStorefrontJob::failed()` writes `Failed`.
- The flow is `RequestStorefrontDeploymentAction` → `ProvisionStorefrontJob` → `StorefrontDeployment`; reconciliation and retry share `StorefrontDeploymentDispatchCommand`. Add a new entry point by reusing that flow, not by dispatching provisioning from a new place.
- Read every storefront setting through the injected `Support\StorefrontSettings` value object. It is bound with `bind()`, not `singleton()`, so a config change is picked up on the next resolve — keep it that way, and do not add `Config::get('vendra-property.*')` calls elsewhere.
- **This package is deliberately coupled to the concrete `Misaf\VendraReseller\Models\Reseller`**, declared as a `self.version` requirement — there is no `PropertyOwner` abstraction. Keep parameters and page hooks named and typed as `reseller` (`ProvisionPropertyAction::execute(..., ?Reseller $reseller = null)`, `CreatePropertyPage::resolveReseller()`). Quota checks go through `Support\PropertyQuota`, which types its subscriber as `SubscriptionSubscriber`.
- Quota enforcement re-reads the reseller under a row lock before counting; two concurrent creates would otherwise both pass the plan limit.
- This package ships shared Filament building blocks (`Filament\Pages\CreatePropertyPage`, `Filament\Schemas\StorefrontConfigurationFields`, `Filament\Actions\ReplaceDomainAction`, `Filament\Concerns\BuildsDailyTrend`), not panel resources — the console and reseller panels own those. If a resource is ever added here, one with a cluster lives in `src/Filament/Clusters/Resources/` and one without lives in `src/Filament/Resources/`.
- Follow Laravel comment style: document with PHPDoc (array shapes, generics, `@see`) and reserve inline comments for genuinely complex logic.
