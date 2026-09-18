<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\ExpireReservations;
use App\Domain\Product;
use App\Domain\Reservation;
use App\Repository\ProductRepository;
use App\Repository\ReservationRepository;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

final class ExpireReservationsTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private ReservationRepository $reservations;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $products = self::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $products);
        $this->products = $products;

        $reservations = self::getContainer()->get(ReservationRepository::class);
        self::assertInstanceOf(ReservationRepository::class, $reservations);
        $this->reservations = $reservations;
    }

    #[Test]
    public function itExpiresReservationsInBatchesAndAggregatesReleasedStockByProduct(): void
    {
        [$productA, $productB, $expiredReservations, $untouchedReservations] = $this->persistScenario();
        $useCase = $this->useCase();

        $result = $useCase->execute(2);

        self::assertSame(3, $result->expiredReservations);
        self::assertSame(9, $result->releasedUnits);
        self::assertSame(2, $result->processedBatches);
        self::assertSame(17, $this->availableStock($productA));
        self::assertSame(10, $this->availableStock($productB));

        foreach ($expiredReservations as $reservation) {
            self::assertSame('expired', $this->persistedStatus($reservation));
        }

        foreach ($untouchedReservations as [$reservation, $expectedStatus]) {
            self::assertSame($expectedStatus, $this->persistedStatus($reservation));
        }

        $secondResult = $useCase->execute(2);

        self::assertSame(0, $secondResult->expiredReservations);
        self::assertSame(0, $secondResult->releasedUnits);
        self::assertSame(0, $secondResult->processedBatches);
        self::assertSame(17, $this->availableStock($productA));
        self::assertSame(10, $this->availableStock($productB));
    }

    #[Test]
    #[DataProvider('invalidBatchSizes')]
    public function itRejectsAnInvalidBatchSizeBeforeOpeningATransaction(int $batchSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expiration batch size must be between 1 and 1000.');

        $this->useCase()->execute($batchSize);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBatchSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [ReservationRepository::MAX_EXPIRATION_BATCH_SIZE + 1];
    }

    /**
     * @return array{
     *     Product,
     *     Product,
     *     list<Reservation>,
     *     list<array{Reservation, string}>
     * }
     */
    private function persistScenario(): array
    {
        $productA = new Product('Concert ticket', 20);
        $productB = new Product('Limited vinyl', 10);
        $this->entityManager->persist($productA);
        $this->entityManager->persist($productB);

        $expiredReservations = [
            $this->newPendingReservation($productA, 2, '2026-01-15 10:00:00'),
            $this->newPendingReservation($productA, 3, '2026-01-15 10:05:00'),
            $this->newPendingReservation($productB, 4, '2026-01-15 10:10:00'),
        ];

        $future = $this->newPendingReservation($productA, 1, '2026-01-15 11:50:00');
        $confirmed = $this->newPendingReservation($productA, 2, '2026-01-15 10:20:00');
        $confirmed->confirm(self::utc('2026-01-15 10:21:00'));

        $untouchedReservations = [
            [$future, 'pending'],
            [$confirmed, 'confirmed'],
        ];

        $this->entityManager->flush();
        $this->entityManager->clear();

        return [$productA, $productB, $expiredReservations, $untouchedReservations];
    }

    private function newPendingReservation(
        Product $product,
        int $quantity,
        string $createdAt,
    ): Reservation {
        $product->reserve($quantity);
        $reservation = Reservation::create(
            $product,
            'user-123',
            $quantity,
            self::utc($createdAt),
        );
        $this->entityManager->persist($reservation);

        return $reservation;
    }

    private function useCase(): ExpireReservations
    {
        return new ExpireReservations(
            $this->entityManager,
            $this->products,
            $this->reservations,
            new MockClock('2026-01-15 12:00:00', 'UTC'),
        );
    }

    private function availableStock(Product $product): int
    {
        return self::databaseInteger($this->connection->fetchOne(
            'SELECT available_stock FROM products WHERE id = ?',
            [self::productId($product)],
        ));
    }

    private function persistedStatus(Reservation $reservation): string
    {
        return self::databaseString($this->connection->fetchOne(
            'SELECT status FROM reservations WHERE id = ?',
            [self::reservationId($reservation)],
        ));
    }

    private static function productId(Product $product): int
    {
        $productId = $product->id();
        self::assertNotNull($productId);

        return $productId;
    }

    private static function reservationId(Reservation $reservation): int
    {
        $reservationId = $reservation->id();
        self::assertNotNull($reservationId);

        return $reservationId;
    }

    private static function utc(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
    }
}
