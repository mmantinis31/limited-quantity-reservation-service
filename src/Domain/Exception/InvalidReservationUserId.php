<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InvalidReservationUserId extends \DomainException
{
    public function __construct(int $maximumLength)
    {
        parent::__construct(sprintf(
            'Reservation user ID must not be blank and must not exceed %d characters.',
            $maximumLength,
        ));
    }
}
