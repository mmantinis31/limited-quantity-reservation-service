<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\ReservationStatus;

final class ReservationCannotBeExpired extends \DomainException
{
    public static function beforeDeadline(\DateTimeImmutable $expiresAt): self
    {
        return new self(sprintf(
            'The reservation cannot be expired before %s.',
            $expiresAt->format(DATE_ATOM),
        ));
    }

    public static function fromStatus(ReservationStatus $status): self
    {
        return new self(sprintf(
            'A reservation with status "%s" cannot be expired.',
            $status->value,
        ));
    }
}
