<?php

namespace App\Enum;

enum CategorieActiviteEnum: string
{
    case DESERT = 'Désert';
    case MER = 'Mer';
    case AERIEN = 'Aérien';
    case NATURE = 'Nature';
    case CULTURE = 'Culture';
    
    public function getIcone(): string
    {
        return match($this) {
            self::DESERT => '🏜️',
            self::MER => '🌊',
            self::AERIEN => '🪂',
            self::NATURE => '🌳',
            self::CULTURE => '🏛️',
        };
    }
}
