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
 * Defaults are fixed, not random, so a filter or sort test can only pass because of the
 * values it sets itself. The copy contains no niche keyword, so it never matches a keyword search by accident.
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
        return [
            'brand_id' => Brand::factory(),
            'niche_id' => fn () => Niche::query()->where('slug', 'kitchen')->value('id'),
            'headline' => 'Plain test headline',
            'body_text' => 'Plain test body.',
            'format' => AdFormat::Image,
            'status' => AdStatus::Active,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'impression_rank' => null,
            'countries' => ['US'],
            'discovered_at' => '2026-01-02 00:00:00+00',
        ];
    }

    public function inactive(?string $endDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdStatus::Inactive,
            'end_date' => $endDate ?? $attributes['start_date'],
        ]);
    }

    public function ranked(int $rank): static
    {
        return $this->state(['impression_rank' => $rank]);
    }

    public function inNiche(string $slug): static
    {
        return $this->state(fn () => ['niche_id' => Niche::query()->where('slug', $slug)->value('id')]);
    }
}
