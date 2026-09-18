<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\CreateReservation;
use App\Domain\Exception\InsufficientStock;
use App\Domain\Exception\InvalidReservationQuantity;
use App\Domain\Product;
use App\Domain\ReservationStatus;
use App\Repository\ProductRepository;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

final class CreateReservationTest extends DatabaseTestCase
{
    private ProductRepository $products;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $products = self::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $products);
        $this->products = $products;
    }

    #[Test]
    public function itAtomicallyCreatesAReservationAndDecrementsAvailableStock(): void
    {
        $product = $this->persistProduct(5);
        $clock = new MockClock('2026-01-15 12:00:00.987654', 'Europe/Vilnius');

        $reservation = $this->useCase($clock)->execute(
            self::productId($product),
            'user-123',
            2,
        );

        self::assertNotNull($reservation->id());
        self::assertSame(ReservationStatus::PENDING, $reservation->status());
        self::assertSame('2026-01-15T10:00:00+00:00', $reservation->createdAt()->format(DATE_ATOM));
        self::assertSame('2026-01-15T10:15:00+00:00', $reservation->expiresAt()->format(DATE_ATOM));
        self::assertSame(3, $this->availableStock($product));
        self::assertSame(1, $this->reservationCount());
    }

    #[Test]
    public function itRollsBackTheWholeTransactionWhenStockIsInsufficient(): void
    {
        $product = $this->persistProduct(1);

        try {
            $this->useCase(new MockClock('2026-01-15 10:00:00', 'UTC'))->execute(
                self::productId($product),
                'user-123',
                2,
            );
            self::fail('A reservation exceeding available stock must fail.');
        } catch (InsufficientStock) {
            self::assertSame(1, $this->availableStock($product));
            self::assertSame(0, $this->reservationCount());
        }
    }

    #[Test]
    public function itDoesNotChangeStockWhenReservationInputIsInvalid(): void
    {
        $product = $this->persistProduct(100);

        try {
            $this->useCase(new MockClock('2026-01-15 10:00:00', 'UTC'))->execute(
                self::productId($product),
                'user-123',
                11,
            );
            self::fail('A reservation above the per-request limit must fail.');
        } catch (InvalidReservationQuantity) {
            self::assertSame(100, $this->availableStock($product));
            self::assertSame(0, $this->reservationCount());
        }
    }

    private function useCase(MockClock $clock): CreateReservation
    {
        return new CreateReservation($this->entityManager, $this->products, $clock);
    }

    private function persistProduct(int $availableStock): Product
    {
        $product = new Product('Concert ticket', $availableStock);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function availableStock(Product $product): int
    {
        return self::databaseInteger($this->connection->fetchOne(
            'SELECT available_stock FROM products WHERE id = ?',
            [self::productId($product)],
        ));
    }

    private function reservationCount(): int
    {
        return self::databaseInteger(
            $this->connection->fetchOne('SELECT COUNT(*) FROM reservations'),
        );
    }

    private static function productId(Product $product): int
    {
        $productId = $product->id();
        self::assertNotNull($productId);

        return $productId;
    }
}
