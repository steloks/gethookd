<?php

namespace App\Models;

use App\Support\NicheCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name'])]
class Niche extends Model
{
    public $timestamps = false;

    /**
     * @return HasMany<Ad, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /**
     * @return list<string>
     */
    public function keywords(): array
    {
        return NicheCatalog::keywords($this->slug);
    }
}
