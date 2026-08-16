<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Misaf\VendraProperty\Actions\ProvisionPropertyAction;
use Misaf\VendraProperty\Actions\RequestStorefrontDeploymentAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;

/**
 * Creating a property is the same operation in every panel: provision the
 * tenant, hand the owner their credentials, and request the storefront.
 *
 * Only the billing reseller is resolved differently — the console picks one from
 * the form, the reseller panel uses the authenticated owner's own — so that is
 * the single hook subclasses fill in. The two pages previously carried identical
 * copies of everything below it, which is how they drift.
 */
abstract class CreatePropertyPage extends CreateRecord
{
    /**
     * @param  array<string, mixed> $data
     *
     * @throws Halt
     */
    protected function handleRecordCreation(array $data): Model
    {
        $domain = $data['domain'] ?? null;
        $email = $data['email'] ?? null;

        if ( ! is_string($domain) || ! is_string($email)) {
            throw new InvalidArgumentException('Invalid property details provided.');
        }

        $reseller = $this->resolveReseller($data);

        try {
            $result = app(ProvisionPropertyAction::class)->execute(
                data: [
                    'domain' => $domain,
                    'email'  => $email,
                ],
                reseller: $reseller,
            );
        } catch (SubscriptionLimitException $exception) {
            Notification::make()
                ->danger()
                ->title(__('console.property_limit_reached'))
                ->body($exception->getMessage())
                ->send();

            throw new Halt();
        }

        Notification::make()
            ->success()
            ->title(__('console.property_created'))
            ->body(__('console.owner_credentials', [
                'username' => $result['user']->username,
                'password' => $result['password'],
            ]))
            ->persistent()
            ->send();

        if ($this->shouldRequestStorefront($data)) {
            app(RequestStorefrontDeploymentAction::class)->execute(
                tenant: $result['tenant'],
                domain: $domain,
                form: $data,
            );
        }

        return $result['tenant'];
    }

    /**
     * The reseller this property is billed to.
     *
     * @param array<string, mixed> $data
     */
    abstract protected function resolveReseller(array $data): Reseller;

    /**
     * Whether a storefront was asked for.
     *
     * Forms that make the storefront mandatory omit the toggle entirely, so an
     * absent key means yes; only an explicit "off" skips it.
     *
     * @param array<string, mixed> $data
     */
    protected function shouldRequestStorefront(array $data): bool
    {
        return false !== ($data['create_storefront'] ?? true);
    }
}
