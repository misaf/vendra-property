<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;
use Misaf\VendraProperty\Enums\StorefrontDesiredState;
use Misaf\VendraProperty\Models\StorefrontDeployment;
use Misaf\VendraTenant\Models\Tenant;

/**
 * @extends Factory<StorefrontDeployment>
 */
final class StorefrontDeploymentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<StorefrontDeployment>
     */
    protected $model = StorefrontDeployment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'slug'          => fake()->unique()->slug(2),
            'domain'        => fake()->unique()->domainName(),
            'theme'         => 'default',
            'configuration' => [
                'name' => ['en' => fake()->company(), 'fa' => 'گل‌فروشی'],
            ],
            'status'        => StorefrontDeploymentStatus::Pending,
            'desired_state' => StorefrontDesiredState::Running,
        ];
    }
}
