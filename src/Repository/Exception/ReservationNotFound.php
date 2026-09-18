<?php

declare(strict_types=1);

namespace App\Repository\Exception;

final class ReservationNotFound extends \RuntimeException
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('Reservation with ID %d was not found.', $reservationId));
    }
}
