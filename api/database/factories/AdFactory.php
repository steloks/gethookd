<?php

namespace Database\Factories;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Models\Ad;
use App\Models\Brand;
use App\Models\Niche;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds single ads for tests. Bulk, deterministic data comes from the seeder, not this factory.
 *
 * @extends Factory<Ad>
 */
class AdFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 years', 'today');

        return [
            'brand_id' => Brand::factory(),
            'niche_id' => fn () => Niche::query()->inRandomOrder()->value('id'),
            'headline' => fake()->sentence(6),
            'body_text' => fake()->paragraph(),
            'format' => fake()->randomElement(AdFormat::cases()),
            'status' => AdStatus::Active,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => null,
            'impression_rank' => fake()->numberBetween(1, 50),
            'countries' => fake()->randomElements(['US', 'GB', 'DE', 'FR', 'CA', 'AU', 'NL', 'ES'], fake()->numberBetween(1, 3)),
            'discovered_at' => $startDate,
        ];
    }

    public function inactive(?string $endDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdStatus::Inactive,
            'end_date' => $endDate ?? $attributes['start_date'],
        ]);
    }

    public function withoutImpressions(): static
    {
        return $this->state(['impression_rank' => null]);
    }

    public function inNiche(string $slug): static
    {
        return $this->state(fn () => ['niche_id' => Niche::query()->where('slug', $slug)->value('id')]);
    }
}
