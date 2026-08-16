<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Actions;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Misaf\VendraPermission\Actions\CreateRoleAction;
use Misaf\VendraProperty\Jobs\CompletePropertyProvisioningJob;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraTenant\Models\Tenant;
use Misaf\VendraTenant\Models\TenantDomain;
use Misaf\VendraUser\Models\User;
use Spatie\Permission\Guard;

/**
 * Creates a property and everything it needs to be usable: the owner user, the
 * administrator role, and the queued work that finishes provisioning.
 *
 * The console and the reseller panel both call this — they differ only in which
 * reseller they resolve — so the flow exists once instead of being copied into
 * each panel.
 */
final class ProvisionPropertyAction
{
    public function __construct(
        private readonly CreatePropertyAction $createPropertyAction,
        private readonly CreateRoleAction $createRoleAction,
    ) {}

    /**
     * @param array{
     *     domain: string,
     *     email: string,
     *     name?: string,
     *     username?: string
     * } $data
     * @return array{tenant: Tenant, user: User, password: string}
     */
    public function execute(array $data, bool $shouldSeed = false, ?string $password = null, ?Reseller $reseller = null): array
    {
        $password ??= Str::password(length: 8, letters: true, numbers: true, symbols: false);
        $domain = TenantDomain::normalizeDomain($data['domain']);
        $name = $data['name'] ?? Str::headline(Str::before($domain, '.'));
        $username = $data['username'] ?? $this->usernameFromEmail($data['email']);

        $result = DB::transaction(function () use ($data, $domain, $name, $username, $password, $reseller, $shouldSeed): array {
            $result = $this->createPropertyAction->execute(
                name: $name,
                domain: $domain,
                username: $username,
                email: $data['email'],
                password: $password,
                reseller: $reseller,
                shouldSeed: $shouldSeed,
            );

            $role = $this->createRoleAction->execute(
                tenant: $result['tenant'],
                name: Config::string('vendra-permission.admin_role'),
                guardName: Guard::getDefaultName(User::class),
            );

            $result['tenant']->execute(fn() => $result['user']->assignRole($role));

            return [
                ...$result,
                'password' => $password,
            ];
        });

        (new RequestJobContext(
            traceId: RequestJobContext::resolveTraceId(),
            operation: 'property_provision_dispatch',
            tenantId: $result['tenant']->id,
            metadata: [ContextKeys::RESELLER_ID => $result['tenant']->reseller_id],
        ))->scope(
            fn() => CompletePropertyProvisioningJob::dispatch($result['tenant']->id)->afterCommit(),
        );

        return $result;
    }

    private function usernameFromEmail(string $email): string
    {
        $username = Str::of($email)
            ->before('@')
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(12, '')
            ->toString();

        return Str::length($username) >= 3 ? $username : 'owner';
    }
}
