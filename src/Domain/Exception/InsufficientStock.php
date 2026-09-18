<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InsufficientStock extends \DomainException
{
    public function __construct(
        public readonly int $requestedQuantity,
        public readonly int $availableStock,
    ) {
        parent::__construct(sprintf(
            'Cannot reserve %d unit(s); only %d unit(s) are available.',
            $requestedQuantity,
            $availableStock,
        ));
    }
}
