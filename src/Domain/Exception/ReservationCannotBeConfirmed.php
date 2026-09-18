<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\ReservationStatus;

final class ReservationCannotBeConfirmed extends \DomainException
{
    public static function becauseExpired(\DateTimeImmutable $expiresAt): self
    {
        return new self(sprintf(
            'The reservation expired at %s and can no longer be confirmed.',
            $expiresAt->format(DATE_ATOM),
        ));
    }

    public static function fromStatus(ReservationStatus $status): self
    {
        return new self(sprintf(
            'A reservation with status "%s" cannot be confirmed.',
            $status->value,
        ));
    }
}
