<?php

use App\Models\Ad;

it('seeds an empty database', function () {
    $this->artisan('db:seed-if-empty')->assertSuccessful();

    expect(Ad::query()->count())->toBeGreaterThanOrEqual(20_000);
});

it('leaves existing ads alone, so restarting the stack keeps the data', function () {
    Ad::factory()->create(['headline' => 'Kept across restarts']);

    $this->artisan('db:seed-if-empty')->assertSuccessful();

    expect(Ad::query()->pluck('headline')->all())->toBe(['Kept across restarts']);
});
