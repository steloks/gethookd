<?php

use App\Models\Niche;

it('has exactly the 12 niches from the spec, with their display names', function () {
    expect(Niche::query()->orderBy('id')->pluck('name', 'slug')->all())->toBe([
        'skincare' => 'Skincare',
        'haircare' => 'Haircare',
        'supplements' => 'Supplements',
        'fitness' => 'Fitness',
        'pet-supplies' => 'Pet Supplies',
        'home-decor' => 'Home Decor',
        'kitchen' => 'Kitchen',
        'apparel' => 'Apparel',
        'jewelry' => 'Jewelry',
        'baby-kids' => 'Baby & Kids',
        'outdoor' => 'Outdoor',
        'tech-accessories' => 'Tech Accessories',
    ]);
});

it('exposes the headline keywords for each niche', function () {
    expect(Niche::query()->where('slug', 'tech-accessories')->sole()->keywords())
        ->toBe(['charger', 'phone case']);
});
