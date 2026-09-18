<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InvalidReservationQuantity extends \DomainException
{
    public function __construct(
        public readonly int $quantity,
        int $minimum,
        int $maximum,
    ) {
        parent::__construct(sprintf(
            'Reservation quantity must be between %d and %d; %d given.',
            $minimum,
            $maximum,
            $quantity,
        ));
    }
}
