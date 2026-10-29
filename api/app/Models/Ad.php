<?php

namespace App\Models;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $brand_id
 * @property int $niche_id
 * @property string $headline
 * @property string $body_text
 * @property AdFormat $format
 * @property AdStatus $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property int|null $impression_rank
 * @property list<string> $countries
 * @property CarbonImmutable $discovered_at
 * @property-read int $days_active
 */
#[Fillable([
    'brand_id', 'niche_id', 'headline', 'body_text', 'format', 'status',
    'start_date', 'end_date', 'impression_rank', 'countries', 'discovered_at',
])]
class Ad extends Model
{
    /** @use HasFactory<AdFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => AdFormat::class,
            'status' => AdStatus::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'impression_rank' => 'integer',
            'countries' => 'array',
            'discovered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Niche, $this>
     */
    public function niche(): BelongsTo
    {
        return $this->belongsTo(Niche::class);
    }

    /**
     * Whole days the ad has run. The single implementation shared by v0, v1 and MCP:
     * active ads count up to today (UTC), inactive ads up to their end_date.
     */
    public function daysActiveOn(?CarbonInterface $today = null): int
    {
        // Compare calendar dates as UTC midnights; the date casts use the app timezone, which may not be UTC.
        $start = CarbonImmutable::parse($this->start_date->toDateString(), 'UTC');
        $until = $this->status === AdStatus::Active
            ? CarbonImmutable::instance($today ?? CarbonImmutable::now())->utc()->startOfDay()
            : CarbonImmutable::parse($this->end_date->toDateString(), 'UTC');

        return (int) $start->diffInDays($until);
    }

    /**
     * @return Attribute<int, never>
     */
    protected function daysActive(): Attribute
    {
        return Attribute::get(fn (): int => $this->daysActiveOn());
    }
}
