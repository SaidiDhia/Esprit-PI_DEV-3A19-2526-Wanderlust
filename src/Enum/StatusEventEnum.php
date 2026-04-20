<?php

namespace App\Enum;

enum StatusEventEnum: string
{
    case EN_ATTENTE = 'en_attente';
    case ACCEPTE = 'accepte';
    case REFUSE = 'refuse';
    case ANNULE = 'annule';
    case TERMINE = 'termine';

    public function getLabel(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'En attente',
            self::ACCEPTE => 'Accepté',
            self::REFUSE => 'Refusé',
            self::ANNULE => 'Annulé',
            self::TERMINE => 'Terminé',
        };
    }

    public function getColor(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'warning',
            self::ACCEPTE => 'success',
            self::REFUSE => 'danger',
            self::ANNULE => 'secondary',
            self::TERMINE => 'info',
        };
    }

    public function getIcon(): string
    {
        return match($this) {
            self::EN_ATTENTE => 'fas fa-clock',
            self::ACCEPTE => 'fas fa-check-circle',
            self::REFUSE => 'fas fa-times-circle',
            self::ANNULE => 'fas fa-ban',
            self::TERMINE => 'fas fa-flag-checkered',
        };
    }
}
