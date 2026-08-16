<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use Illuminate\Support\Arr;

/**
 * Translates the flat `storefront_*` console form into the nested configuration
 * the storefront image boots on.
 *
 * Declarative on purpose. The mapping used to be twenty-odd hand-written
 * assignments in the action, which meant the console defined the field names in
 * one class and re-typed them as string literals in another — renaming a field
 * broke provisioning silently. Here the correspondence is data, and a test
 * asserts every key still exists in the Filament schema.
 */
final class StorefrontConfigurationMap
{
    /**
     * Form field name to its dot path in the configuration.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'storefront_name_en'            => 'name.en',
        'storefront_name_fa'            => 'name.fa',
        'storefront_business_type'      => 'businessType',
        'storefront_price_currency'     => 'priceCurrency',
        'storefront_og_image'           => 'ogImage',
        'storefront_locality'           => 'address.locality',
        'storefront_country'            => 'address.country',
        'storefront_mobile_phone'       => 'contact.mobilePhone',
        'storefront_office_phone'       => 'contact.officePhone',
        'storefront_contact_email'      => 'contact.email',
        'storefront_hours_open'         => 'contact.hoursOpen',
        'storefront_hours_close'        => 'contact.hoursClose',
        'storefront_map_query'          => 'contact.mapQuery',
        'storefront_whatsapp_phone'     => 'social.whatsappPhone',
        'storefront_telegram_username'  => 'social.telegramUsername',
        'storefront_instagram_username' => 'social.instagramUsername',
    ];

    /**
     * Fields the storefront expects as uppercase codes (ISO currency, ISO country).
     *
     * @var list<string>
     */
    private const array UPPERCASED = ['storefront_price_currency', 'storefront_country'];

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function toConfiguration(array $form): array
    {
        $configuration = [];

        foreach (self::FIELDS as $field => $path) {
            Arr::set($configuration, $path, self::value($form, $field));
        }

        // Per-locale copy overrides. One image serves the whole fleet and its
        // message catalogue is deliberately brand-neutral, so this is the only
        // channel a property has for wording of its own — without it every
        // storefront reads identically. Omitted when empty: the storefront
        // treats an absent key and an empty object the same way.
        $messages = self::messages($form);

        if ([] !== $messages) {
            $configuration['messages'] = $messages;
        }

        return $configuration;
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function value(array $form, string $field): string
    {
        $value = $form[$field] ?? null;
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($field, self::UPPERCASED, true) ? mb_strtoupper($value) : $value;
    }

    /**
     * Locale-keyed message overrides, deep-merged over the storefront's base
     * catalogue at render time.
     *
     * Shape: ['en' => ['products' => ['title' => 'Our Breads']], 'fa' => [...]].
     * Anything that is not an array keyed by a locale string is dropped rather
     * than passed on, because the storefront validates the encoded
     * configuration at boot and refuses to render when it does not parse.
     *
     * @param  array<string, mixed>                 $form
     * @return array<string, array<string, mixed>>
     */
    private static function messages(array $form): array
    {
        $value = $form['storefront_messages'] ?? null;

        if ( ! is_array($value)) {
            return [];
        }

        $messages = [];

        foreach ($value as $locale => $overrides) {
            if (is_string($locale) && '' !== $locale && is_array($overrides) && [] !== $overrides) {
                $messages[$locale] = $overrides;
            }
        }

        return $messages;
    }
}
