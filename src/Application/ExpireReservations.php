<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Reservation;
use App\Repository\ProductRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class ExpireReservations
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductRepository $products,
        private ReservationRepository $reservations,
        private ClockInterface $clock,
    ) {
    }

    public function execute(int $batchSize = self::DEFAULT_BATCH_SIZE): ExpireReservationsResult
    {
        self::assertValidBatchSize($batchSize);

        $cutoff = $this->clock->now();
        $expiredReservations = 0;
        $releasedUnits = 0;
        $processedBatches = 0;

        while (true) {
            $batch = $this->entityManager->wrapInTransaction(
                fn (): array => $this->expireBatch($cutoff, $batchSize),
            );

            if (0 === $batch['expiredReservations']) {
                break;
            }

            $expiredReservations += $batch['expiredReservations'];
            $releasedUnits += $batch['releasedUnits'];
            ++$processedBatches;

            $this->entityManager->clear();
        }

        return new ExpireReservationsResult(
            $expiredReservations,
            $releasedUnits,
            $processedBatches,
        );
    }

    /**
     * @return array{expiredReservations: int, releasedUnits: int}
     */
    private function expireBatch(\DateTimeImmutable $cutoff, int $batchSize): array
    {
        $reservations = $this->reservations->findExpiredPendingForUpdate($cutoff, $batchSize);

        if ([] === $reservations) {
            return ['expiredReservations' => 0, 'releasedUnits' => 0];
        }

        /** @var array<int, true> $productIds */
        $productIds = [];

        foreach ($reservations as $reservation) {
            $productIds[self::productId($reservation)] = true;
        }

        ksort($productIds, SORT_NUMERIC);

        $lockedProducts = [];

        foreach (array_keys($productIds) as $productId) {
            $lockedProducts[$productId] = $this->products->getForUpdate($productId);
        }

        /** @var array<int, int> $releasedUnitsByProduct */
        $releasedUnitsByProduct = [];

        foreach ($reservations as $reservation) {
            if (!$reservation->expire($cutoff)) {
                throw new \LogicException(sprintf('Pending reservation %d was already expired while holding its write lock.', $reservation->id()));
            }

            $productId = self::productId($reservation);
            $releasedUnitsByProduct[$productId] = ($releasedUnitsByProduct[$productId] ?? 0)
                + $reservation->quantity();
        }

        foreach ($releasedUnitsByProduct as $productId => $quantity) {
            $lockedProducts[$productId]->release($quantity);
        }

        return [
            'expiredReservations' => count($reservations),
            'releasedUnits' => array_sum($releasedUnitsByProduct),
        ];
    }

    private static function assertValidBatchSize(int $batchSize): void
    {
        if ($batchSize < 1 || $batchSize > ReservationRepository::MAX_EXPIRATION_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf('Expiration batch size must be between 1 and %d.', ReservationRepository::MAX_EXPIRATION_BATCH_SIZE));
        }
    }

    private static function productId(Reservation $reservation): int
    {
        return $reservation->product()->id()
            ?? throw new \LogicException('A persisted reservation must reference a persisted product.');
    }
}
