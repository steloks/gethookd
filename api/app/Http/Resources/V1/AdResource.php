<?php

namespace App\Http\Resources\V1;

use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A v1 ad, with the spec's data-model field names. Shared by the detail and search endpoints.
 *
 * @mixin Ad
 */
class AdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'brand_name' => $this->brand->name,
            'headline' => $this->headline,
            'body_text' => $this->body_text,
            'niche' => $this->niche->slug,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days_active' => $this->days_active,
            'impression_rank' => $this->impression_rank,
            'countries' => $this->countries,
            'discovered_at' => $this->discovered_at->utc()->toIso8601ZuluString(),
        ];
    }
}
