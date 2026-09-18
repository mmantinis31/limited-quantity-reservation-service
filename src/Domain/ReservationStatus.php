<?php

declare(strict_types=1);

namespace App\Domain;

enum ReservationStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case EXPIRED = 'expired';
}
