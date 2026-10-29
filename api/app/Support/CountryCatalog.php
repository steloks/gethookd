<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 codes that synthetic ads run in. Shared by the seed and the live ingest.
 */
final class CountryCatalog
{
    /**
     * @var list<string>
     */
    public const CODES = ['US', 'GB', 'CA', 'AU', 'DE', 'FR', 'NL', 'ES', 'IT', 'SE', 'IE', 'NZ', 'BR', 'MX', 'JP'];
}
