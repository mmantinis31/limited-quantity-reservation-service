<?php

declare(strict_types=1);

namespace App\Application;

final readonly class ExpireReservationsResult
{
    public function __construct(
        public int $expiredReservations,
        public int $releasedUnits,
        public int $processedBatches,
    ) {
    }
}
