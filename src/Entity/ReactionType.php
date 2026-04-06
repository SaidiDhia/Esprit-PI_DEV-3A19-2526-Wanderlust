<?php

namespace App\Entity;

enum ReactionType: string
{
    case LIKE = 'LIKE';
    case LOVE = 'LOVE';
    case HAHA = 'HAHA';
    case WOW = 'WOW';
    case SAD = 'SAD';
    case ANGRY = 'ANGRY';
}