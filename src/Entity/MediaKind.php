<?php

declare(strict_types=1);

namespace App\Entity;

enum MediaKind: string
{
    case Photo = 'photo';
    case Audio = 'audio';
}
