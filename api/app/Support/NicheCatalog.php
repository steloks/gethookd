<?php

namespace App\Support;

/**
 * The fixed list of 12 niches from the assessment spec.
 *
 * The niches table is populated from this list by its migration, so the
 * rows exist in every environment, including tests. Keywords are the
 * words the seed must use in some headlines for that niche.
 */
final class NicheCatalog
{
    /**
     * @var array<string, array{name: string, keywords: list<string>}>
     */
    public const NICHES = [
        'skincare' => ['name' => 'Skincare', 'keywords' => ['serum', 'glow', 'SPF']],
        'haircare' => ['name' => 'Haircare', 'keywords' => ['shampoo', 'curls']],
        'supplements' => ['name' => 'Supplements', 'keywords' => ['protein', 'gummies']],
        'fitness' => ['name' => 'Fitness', 'keywords' => ['leggings', 'dumbbell']],
        'pet-supplies' => ['name' => 'Pet Supplies', 'keywords' => ['collar', 'kibble']],
        'home-decor' => ['name' => 'Home Decor', 'keywords' => ['sofa', 'candle']],
        'kitchen' => ['name' => 'Kitchen', 'keywords' => ['blender', 'knife']],
        'apparel' => ['name' => 'Apparel', 'keywords' => ['hoodie', 'denim']],
        'jewelry' => ['name' => 'Jewelry', 'keywords' => ['necklace', 'ring']],
        'baby-kids' => ['name' => 'Baby & Kids', 'keywords' => ['stroller', 'onesie']],
        'outdoor' => ['name' => 'Outdoor', 'keywords' => ['tent', 'backpack']],
        'tech-accessories' => ['name' => 'Tech Accessories', 'keywords' => ['charger', 'phone case']],
    ];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::NICHES);
    }

    /**
     * @return list<string>
     */
    public static function keywords(string $slug): array
    {
        return self::NICHES[$slug]['keywords'];
    }
}
