<?php

namespace App\Enums;

enum AdFormat: string
{
    case Image = 'image';
    case Video = 'video';
    case Carousel = 'carousel';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
