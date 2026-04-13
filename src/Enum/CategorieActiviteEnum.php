<?php

namespace App\Enum;

enum CategorieActiviteEnum: string
{
    case DESERT = 'desert';
    case MER = 'Mer';
    case AERIEN = 'Aérien';
    case NATURE = 'nature';
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
    
    public function getLabel(): string
    {
        return match($this) {
            self::DESERT => 'Désert',
            self::MER => 'Mer',
            self::AERIEN => 'Aérien',
            self::NATURE => 'Nature',
            self::CULTURE => 'Culture',
        };
    }
}