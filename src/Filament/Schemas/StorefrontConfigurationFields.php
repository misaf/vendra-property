<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Filament\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Misaf\VendraProperty\Models\StorefrontDeployment;

final class StorefrontConfigurationFields
{
    /**
     * @return list<Section>
     */
    public static function make(bool $optional): array
    {
        return [
            Section::make(__('console.storefront_configuration'))
                ->description(__('console.storefront_configuration_description'))
                ->schema([
                    ...($optional ? [
                        Toggle::make('create_storefront')
                            ->label(__('console.create_storefront'))
                            ->helperText(__('console.create_storefront_hint'))
                            ->default(false)
                            ->live()
                            ->columnSpanFull(),
                    ] : []),
                    Grid::make(2)
                        ->schema([
                            ...self::identityFields($optional),
                            ...self::contactFields($optional),
                            ...self::locationAndSocialFields($optional),
                        ])
                        ->visible(fn(Get $get): bool => ! $optional || true === $get('create_storefront'))
                        ->columnSpanFull(),
                ])
                ->visibleOn('create')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<TextInput|Hidden>
     */
    public static function identityFields(bool $optional): array
    {
        $required = fn(Get $get): bool => ! $optional || true === $get('create_storefront');
        return [
            TextInput::make('storefront_slug')
                ->label(__('console.storefront_slug'))
                ->helperText(__('console.storefront_slug_hint'))
                ->required($required)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->unique(StorefrontDeployment::class, 'slug')
                ->extraAttributes(['dir' => 'ltr'])
                ->placeholder('rose-garden')
                ->maxLength(100),
            Hidden::make('storefront_theme')
                ->default('default')
                ->required($required)
                ->dehydrated(),
            TextInput::make('storefront_name_en')
                ->label(__('console.storefront_name_en'))
                ->required($required)
                ->maxLength(255),
            TextInput::make('storefront_name_fa')
                ->label(__('console.storefront_name_fa'))
                ->required($required)
                ->maxLength(255),
            Hidden::make('storefront_business_type')
                ->default('Florist')
                ->required($required)
                ->dehydrated(),
            TextInput::make('storefront_price_currency')
                ->label(__('console.storefront_price_currency'))
                ->default('IRR')
                ->required($required)
                ->length(3),
            TextInput::make('storefront_og_image')
                ->label(__('console.storefront_og_image'))
                ->helperText(__('console.storefront_og_image_hint'))
                ->maxLength(2048),
        ];
    }

    /**
     * @return list<TextInput>
     */
    public static function contactFields(bool $optional): array
    {
        $required = fn(Get $get): bool => ! $optional || true === $get('create_storefront');

        return [
            TextInput::make('storefront_mobile_phone')
                ->label(__('console.storefront_mobile_phone'))
                ->required($required)
                ->tel()
                ->extraAttributes(['dir' => 'ltr'])
                ->placeholder('09121234567')
                ->maxLength(50),
            TextInput::make('storefront_office_phone')
                ->label(__('console.storefront_office_phone'))
                ->required($required)
                ->tel()
                ->extraAttributes(['dir' => 'ltr'])
                ->placeholder('02112345678')
                ->maxLength(50),
            TextInput::make('storefront_contact_email')
                ->label(__('console.storefront_contact_email'))
                ->required($required)
                ->email()
                ->autocomplete('email')
                ->extraAttributes(['dir' => 'ltr'])
                ->placeholder('contact@example.com')
                ->maxLength(255),
            TextInput::make('storefront_hours_open')
                ->label(__('console.storefront_hours_open'))
                ->placeholder('08:00')
                ->extraAttributes(['dir' => 'ltr'])
                ->required($required)
                ->regex('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/'),
            TextInput::make('storefront_hours_close')
                ->label(__('console.storefront_hours_close'))
                ->placeholder('21:00')
                ->extraAttributes(['dir' => 'ltr'])
                ->required($required)
                ->regex('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/'),
        ];
    }

    /**
     * @return list<TextInput>
     */
    public static function locationAndSocialFields(bool $optional): array
    {
        $required = fn(Get $get): bool => ! $optional || true === $get('create_storefront');

        return [
            TextInput::make('storefront_locality')
                ->label(__('console.storefront_locality'))
                ->required($required)
                ->maxLength(255),
            TextInput::make('storefront_country')
                ->label(__('console.storefront_country'))
                ->default('IR')
                ->extraAttributes(['dir' => 'ltr'])
                ->required($required)
                ->length(2),
            TextInput::make('storefront_map_query')
                ->label(__('console.storefront_map_query'))
                ->required($required)
                ->placeholder('35.6892, 51.3890')
                ->maxLength(500),
            TextInput::make('storefront_whatsapp_phone')
                ->label(__('console.storefront_whatsapp_phone'))
                ->required($required)
                ->tel()
                ->extraAttributes(['dir' => 'ltr'])
                ->placeholder('+989121234567')
                ->maxLength(50),
            TextInput::make('storefront_telegram_username')
                ->label(__('console.storefront_telegram_username'))
                ->required($required)
                ->prefix('@')
                ->extraAttributes(['dir' => 'ltr'])
                ->maxLength(100),
            TextInput::make('storefront_instagram_username')
                ->label(__('console.storefront_instagram_username'))
                ->required($required)
                ->prefix('@')
                ->extraAttributes(['dir' => 'ltr'])
                ->maxLength(100),
        ];
    }
}
