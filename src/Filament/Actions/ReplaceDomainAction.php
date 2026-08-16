<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Misaf\VendraTenant\Actions\ReplaceTenantDomainAction;
use Misaf\VendraTenant\Models\Tenant;
use Misaf\VendraTenant\Models\TenantDomain;

/**
 * Shared "replace the property's active domain" table action. Panels subclass
 * this and override {@see authorizationCallback()} to apply their own access
 * rules; the form, validation, and replace behaviour stay identical.
 */
abstract class ReplaceDomainAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'replaceDomain';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('console.replace_domain'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                TextInput::make('domain')
                    ->label(__('console.new_domain'))
                    ->required()
                    ->maxLength(255)
                    ->rules(TenantDomain::activeDomainRules())
                    ->dehydrateStateUsing(fn(?string $state): ?string => null === $state
                        ? null
                        : TenantDomain::normalizeDomain($state)),
            ])
            ->action(function (Tenant $record, array $data): void {
                $domain = $data['domain'];

                if ( ! is_string($domain)) {
                    return;
                }

                app(ReplaceTenantDomainAction::class)->execute($record, $domain);

                Notification::make()
                    ->success()
                    ->title(__('console.domain_replaced'))
                    ->send();
            });

        $authorization = $this->authorizationCallback();

        if (null !== $authorization) {
            $this->authorize($authorization);
        }
    }

    /**
     * The authorization gate for this action, or null to leave it unrestricted.
     *
     * @return (Closure(): bool)|null
     */
    protected function authorizationCallback(): ?Closure
    {
        return null;
    }
}
