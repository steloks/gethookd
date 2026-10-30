<?php

namespace App\Enums;

/**
 * API key scopes, stored as Sanctum token abilities.
 */
enum ApiScope: string
{
    case SearchRead = 'search:read';
    case KeysManage = 'keys:manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
