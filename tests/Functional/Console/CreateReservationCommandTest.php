<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Command\CreateReservationCommand;
use App\Domain\Product;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateReservationCommandTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private CreateReservationCommand $command;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable('2026-01-15 10:05:00', new \DateTimeZone('UTC')));

        $command = self::getContainer()->get(CreateReservationCommand::class);
        self::assertInstanceOf(CreateReservationCommand::class, $command);
        $this->command = $command;
    }

    #[Test]
    public function itCreatesAReservationUsingTheApplicationUseCase(): void
    {
        $product = $this->persistProduct(5);
        $tester = $this->tester();

        $exitCode = $tester->execute([
            'product-id' => (string) self::productId($product),
            'user-id' => 'user-123',
            'quantity' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Reservation created successfully.', $tester->getDisplay());
        self::assertStringContainsString('2026-01-15T10:20:00+00:00', $tester->getDisplay());
        self::assertSame(3, $this->availableStock($product));
        self::assertSame(1, self::databaseInteger(
            $this->connection->fetchOne('SELECT COUNT(*) FROM reservations'),
        ));
        self::assertSame('pending', self::databaseString(
            $this->connection->fetchOne('SELECT status FROM reservations LIMIT 1'),
        ));
    }

    #[Test]
    public function itReturnsInvalidForANonIntegerProductId(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([
            'product-id' => 'not-an-integer',
            'user-id' => 'user-123',
            'quantity' => '1',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Product ID must be an integer.', $tester->getDisplay());
    }

    #[Test]
    public function itReturnsInvalidWithoutChangingStockForAnInvalidQuantity(): void
    {
        $product = $this->persistProduct(100);
        $tester = $this->tester();

        $exitCode = $tester->execute([
            'product-id' => (string) self::productId($product),
            'user-id' => 'user-123',
            'quantity' => '11',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Reservation quantity must be between 1 and 10', $tester->getDisplay());
        self::assertSame(100, $this->availableStock($product));
    }

    #[Test]
    public function itReturnsFailureWhenTheProductDoesNotExist(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([
            'product-id' => '999999',
            'user-id' => 'user-123',
            'quantity' => '1',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Product with ID 999999 was not found.', $tester->getDisplay());
    }

    #[Test]
    public function itReturnsFailureWithoutChangingStateWhenStockIsInsufficient(): void
    {
        $product = $this->persistProduct(1);
        $tester = $this->tester();

        $exitCode = $tester->execute([
            'product-id' => (string) self::productId($product),
            'user-id' => 'user-123',
            'quantity' => '2',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('only 1 unit(s) are available', $tester->getDisplay());
        self::assertSame(1, $this->availableStock($product));
        self::assertSame(0, self::databaseInteger(
            $this->connection->fetchOne('SELECT COUNT(*) FROM reservations'),
        ));
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

    private function availableStock(Product $product): int
    {
        return self::databaseInteger($this->connection->fetchOne(
            'SELECT available_stock FROM products WHERE id = ?',
            [self::productId($product)],
        ));
    }

    private static function productId(Product $product): int
    {
        $productId = $product->id();
        self::assertNotNull($productId);

        return $productId;
    }
}
