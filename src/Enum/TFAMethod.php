<?php

namespace App\Enum;

enum TFAMethod: string
{
    case NONE = 'NONE';
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';
    case WHATSAPP = 'WHATSAPP';
    case APP = 'APP';
    case FACE_ID = 'FACE_ID';

    public function getLabel(): string
    {
        return match($this) {
            self::NONE => 'None',
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::WHATSAPP => 'WhatsApp',
            self::APP => 'Authenticator App',
            self::FACE_ID => 'Face ID',
        };
    }
}
