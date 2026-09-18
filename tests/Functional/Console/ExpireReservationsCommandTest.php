<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Command\ExpireReservationsCommand;
use App\Domain\Product;
use App\Domain\Reservation;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExpireReservationsCommandTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private ExpireReservationsCommand $command;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable('2026-01-15 10:05:00', new \DateTimeZone('UTC')));

        $command = self::getContainer()->get(ExpireReservationsCommand::class);
        self::assertInstanceOf(ExpireReservationsCommand::class, $command);
        $this->command = $command;
    }

    #[Test]
    public function itExpiresReservationsAndPrintsTheProcessingSummary(): void
    {
        $product = $this->persistProduct(10);
        $expiredReservations = [
            $this->persistPendingReservation($product, 2, '2026-01-15 09:00:00'),
            $this->persistPendingReservation($product, 3, '2026-01-15 09:05:00'),
        ];
        $futureReservation = $this->persistPendingReservation($product, 1, '2026-01-15 10:00:00');
        $this->entityManager->flush();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--batch-size' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Expired reservation processing completed.', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Expired reservations\s+2/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Released units\s+5/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Processed batches\s+2/', $tester->getDisplay());
        self::assertSame(9, $this->availableStock($product));

        foreach ($expiredReservations as $reservation) {
            self::assertSame('expired', $this->persistedStatus($reservation));
        }

        self::assertSame('pending', $this->persistedStatus($futureReservation));
    }

    #[Test]
    public function itSucceedsWhenThereAreNoReservationsToExpire(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/Expired reservations\s+0/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Released units\s+0/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Processed batches\s+0/', $tester->getDisplay());
    }

    #[Test]
    public function itReturnsInvalidForAnUnsupportedBatchSize(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--batch-size' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Expiration batch size must be between 1 and 1000.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command);
    }

    private function persistProduct(int $availableStock): Product
    {
        $product = new Product('Concert ticket', $availableStock);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function persistPendingReservation(
        Product $product,
        int $quantity,
        string $createdAt,
    ): Reservation {
        $product->reserve($quantity);
        $reservation = Reservation::create(
            $product,
            'user-123',
            $quantity,
            new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
        );
        $this->entityManager->persist($reservation);

        return $reservation;
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
}
