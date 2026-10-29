<?php

namespace App\Seeding;

use App\Enums\AdFormat;
use App\Support\CountryCatalog;
use Random\Randomizer;

/**
 * Generates synthetic brand names, ad copy, formats and countries from fixed lists.
 *
 * Shared by the deterministic seeder (seeded engine) and the live ingest (random engine).
 * Word lists avoid substrings of other niches' keywords ("ring" in "during", "tent" in "content")
 * so a keyword search only matches ads from the niche that owns the keyword.
 */
final class SyntheticAdText
{
    /**
     * Non-keyword products per niche, so not every headline is a keyword headline.
     *
     * @var array<string, list<string>>
     */
    private const PRODUCTS = [
        'skincare' => ['moisturizer', 'cleanser', 'toner', 'face mask', 'eye cream'],
        'haircare' => ['conditioner', 'hair oil', 'styling cream', 'hair mask', 'scalp scrub'],
        'supplements' => ['multivitamin', 'collagen powder', 'magnesium', 'omega-3 capsules', 'electrolytes'],
        'fitness' => ['yoga mat', 'resistance bands', 'kettlebell', 'sports bra', 'jump rope'],
        'pet-supplies' => ['dog bed', 'cat tree', 'chew toy', 'leash', 'litter box'],
        'home-decor' => ['throw pillow', 'wall art', 'rug', 'vase', 'floor lamp'],
        'kitchen' => ['cookware set', 'cutting board', 'air fryer', 'coffee grinder', 'cast iron pan'],
        'apparel' => ['t-shirt', 'jacket', 'sneakers', 'chinos', 'sweater'],
        'jewelry' => ['bracelet', 'pendant', 'anklet', 'charm', 'brooch'],
        'baby-kids' => ['car seat', 'crib', 'baby carrier', 'play mat', 'sippy cup'],
        'outdoor' => ['sleeping bag', 'hiking boots', 'camp stove', 'trekking poles', 'cooler'],
        'tech-accessories' => ['wireless earbuds', 'laptop stand', 'power bank', 'cable organizer', 'screen protector'],
    ];

    private const ADJECTIVES = [
        'Lightweight', 'Bestselling', 'Premium', 'Eco-friendly', 'Award-winning',
        'Handmade', 'Everyday', 'Limited edition', 'Ultra-soft', 'Vegan',
    ];

    private const AUDIENCES = [
        'busy mornings', 'every budget', 'gift season', 'travel', 'everyday use', 'small spaces',
    ];

    private const HEADLINES = [
        '%adj% %product% for %audience%',
        'Meet the %product% customers keep buying',
        'Our %adjLower% %product% just dropped',
        'Why thousands switched to this %product%',
        'Save %pct%%% on the %product% everyone is talking about',
        'The %product% you will actually use',
    ];

    private const BODY_SENTENCES = [
        'Free shipping on orders over $%n%.',
        'Rated %stars% stars by %count% happy customers.',
        'Made with %adjLower% materials and backed by our satisfaction guarantee.',
        'Limited stock, so order today and get it by %weekday%.',
        'Designed for %audience%.',
        'Try it risk-free for %days% days.',
    ];

    private const BRAND_PREFIXES = [
        'Nova', 'Lumi', 'Bright', 'Pure', 'Bold', 'Urban', 'Wild', 'Kind', 'True', 'Happy',
        'Golden', 'Silver', 'Blue', 'Green', 'Little', 'North', 'Sunny', 'Moon', 'Peak', 'Cozy',
        'Swift', 'Daily', 'Fresh', 'Noble', 'Vivid',
    ];

    private const BRAND_SUFFIXES = [
        'Labs', 'Co', 'Goods', 'Studio', 'House', 'Supply', 'Collective', 'Works',
        'Market', 'Bloom', 'Nest', 'Craft', 'Origin', 'Theory', 'Club',
    ];

    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    public function __construct(private readonly Randomizer $random) {}

    /**
     * Distinct brand names in a random order.
     *
     * @return list<string>
     */
    public function brandNames(int $count): array
    {
        $names = [];
        foreach (self::BRAND_PREFIXES as $prefix) {
            foreach (self::BRAND_SUFFIXES as $suffix) {
                $names[] = "{$prefix} {$suffix}";
            }
        }

        if ($count > count($names)) {
            throw new \InvalidArgumentException('Only '.count($names).' distinct brand names are available.');
        }

        return array_slice($this->random->shuffleArray($names), 0, $count);
    }

    /**
     * About 70% of headlines name one of the niche's spec keywords.
     *
     * @param  list<string>  $keywords
     */
    public function headline(string $niche, array $keywords): string
    {
        $product = $this->random->getInt(1, 10) <= 7
            ? $this->pick($keywords)
            : $this->pick(self::PRODUCTS[$niche]);

        return ucfirst($this->fill($this->pick(self::HEADLINES), $product));
    }

    public function bodyText(string $niche): string
    {
        $sentences = $this->random->pickArrayKeys(self::BODY_SENTENCES, 2);
        $product = $this->pick(self::PRODUCTS[$niche]);

        $text = array_map(fn (int $key) => $this->fill(self::BODY_SENTENCES[$key], $product), $sentences);
        $text[] = ucfirst("pair it with our {$product}.");

        return implode(' ', $text);
    }

    /**
     * Image 50%, video 35%, carousel 15%.
     */
    public function format(): AdFormat
    {
        $roll = $this->random->getInt(1, 100);

        return match (true) {
            $roll <= 50 => AdFormat::Image,
            $roll <= 85 => AdFormat::Video,
            default => AdFormat::Carousel,
        };
    }

    /**
     * One to four distinct codes from the catalog.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        $keys = $this->random->pickArrayKeys(CountryCatalog::CODES, $this->random->getInt(1, 4));

        return array_map(fn (int $key) => CountryCatalog::CODES[$key], $keys);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    private function fill(string $template, string $product): string
    {
        $adjective = $this->pick(self::ADJECTIVES);

        return strtr($template, [
            '%adj%' => $adjective,
            '%adjLower%' => strtolower($adjective),
            '%product%' => $product,
            '%audience%' => $this->pick(self::AUDIENCES),
            '%pct%' => (string) ($this->random->getInt(2, 8) * 5),
            '%%' => '%',
            '%n%' => (string) ($this->random->getInt(3, 10) * 10),
            '%stars%' => (string) ($this->random->getInt(40, 50) / 10),
            '%count%' => number_format($this->random->getInt(200, 20000)),
            '%weekday%' => $this->pick(self::WEEKDAYS),
            '%days%' => (string) $this->pick([14, 30, 60, 90]),
        ]);
    }
}
