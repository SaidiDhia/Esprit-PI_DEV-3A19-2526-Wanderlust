<?php

namespace App\Enum;

enum RoleEnum: string
{
    case ADMIN = 'ADMIN';
    case HOST = 'HOST';
    case PARTICIPANT = 'PARTICIPANT';

    public function getLabel(): string
    {
        return match($this) {
            self::ADMIN => 'Admin',
            self::HOST => 'Host',
            self::PARTICIPANT => 'Traveler',
        };
    }
}
