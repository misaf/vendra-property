<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use InvalidArgumentException;

/**
 * Validates a storefront before a container is created for it.
 *
 * The storefront image validates its encoded configuration at boot and refuses
 * to render without the required fields, so an unchecked deployment turns into a
 * crash-looping container and a deployment row stuck in "requested". Failing here
 * turns that into an immediate failure naming the missing field.
 *
 * Formerly named `StorefrontSpecification`, which promised the composability of
 * the Specification pattern that it never had: this throws with an operator-facing
 * message rather than answering a boolean, so it is named for what it does. The
 * same field maps also drive {@see deploymentRules()}, so the console form and
 * the provisioner agree on what "complete" means instead of describing it twice.
 */
final class StorefrontConfigurationValidator
{
    private const string SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const string DOMAIN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i';

    /**
     * Identity fields the platform derives from the deployment row rather than
     * the console form, so they are excluded from the form-time rules.
     *
     * @var list<string>
     */
    private const array IDENTITY = ['slug', 'theme', 'domain', 'siteUrl'];

    /**
     * Fields the image requires at boot, mirroring its properties/schema.json.
     *
     * ogImage is deliberately absent: it is optional there, and the console sends
     * an empty string rather than omitting the key when a property has no share
     * image, so requiring it would reject a configuration the image accepts.
     *
     * @var list<string>
     */
    private const array REQUIRED_STRINGS = ['slug', 'theme', 'domain', 'siteUrl', 'businessType', 'priceCurrency'];

    /** @var array<string, list<string>> */
    private const array REQUIRED_OBJECTS = [
        'address' => ['locality', 'country'],
        'contact' => ['mobilePhone', 'officePhone', 'email', 'hoursOpen', 'hoursClose', 'mapQuery'],
        'name'    => [],
        'social'  => ['whatsappPhone', 'telegramUsername', 'instagramUsername'],
    ];

    /**
     * Validation rules for the configuration the console assembles, ready for
     * `Validator::make()`. Identity fields are omitted — the platform supplies
     * those when the deployment is provisioned, not when the form is submitted.
     *
     * @return array<string, string>
     */
    public static function deploymentRules(): array
    {
        $rules = [];

        foreach (self::REQUIRED_STRINGS as $key) {
            if ( ! in_array($key, self::IDENTITY, true)) {
                $rules[$key] = 'required|string';
            }
        }

        foreach (self::REQUIRED_OBJECTS as $key => $fields) {
            $rules[$key] = 'required|array';

            foreach ($fields as $field) {
                $rules[$key . '.' . $field] = 'required|string';
            }
        }

        return $rules;
    }

    /**
     * @param  list<string> $themes published themes, one per built image
     *
     * @throws InvalidArgumentException
     */
    public function validate(StorefrontProvisionRequest $request, array $themes): void
    {
        if (1 !== preg_match(self::SLUG, $request->slug)) {
            throw new InvalidArgumentException('The storefront slug must contain lowercase letters, digits, and hyphens.');
        }

        if (1 !== preg_match(self::DOMAIN, $request->domain)) {
            throw new InvalidArgumentException('The storefront domain is invalid.');
        }

        if ('' === mb_trim($request->image)) {
            throw new InvalidArgumentException('A storefront image is required.');
        }

        /*
         | A theme is a property of the image: the storefront resolves it at build
         | time, so a different theme means a different image. The platform records
         | the theme and checks the configuration agrees, but cannot change it at
         | deploy time.
         */
        if ( ! in_array($request->theme, $themes, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported storefront theme [%s]. Published themes: %s.',
                '' === $request->theme ? 'null' : $request->theme,
                implode(', ', $themes),
            ));
        }

        $configuration = $request->configuration;

        if (($configuration['slug'] ?? null) !== $request->slug || ($configuration['domain'] ?? null) !== $request->domain) {
            throw new InvalidArgumentException('The storefront configuration identity does not match the deployment.');
        }

        $missing = $this->missingFields($configuration);

        if ([] !== $missing) {
            throw new InvalidArgumentException(
                'The storefront configuration is missing required fields: ' . implode(', ', $missing) . '.',
            );
        }
    }

    /**
     * @param  array<array-key, mixed> $configuration
     * @return list<string>
     */
    private function missingFields(array $configuration): array
    {
        $missing = [];

        foreach (self::REQUIRED_STRINGS as $key) {
            $value = $configuration[$key] ?? null;

            if ( ! is_string($value) || '' === mb_trim($value)) {
                $missing[] = $key;
            }
        }

        foreach (self::REQUIRED_OBJECTS as $key => $fields) {
            $nested = $configuration[$key] ?? null;

            if ( ! is_array($nested) || [] === $nested) {
                $missing[] = $key;

                continue;
            }

            foreach ($fields as $field) {
                $value = $nested[$field] ?? null;

                if ( ! is_string($value) || '' === mb_trim($value)) {
                    $missing[] = $key . '.' . $field;
                }
            }
        }

        return $missing;
    }
}
