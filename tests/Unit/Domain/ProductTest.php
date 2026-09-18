<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Exception\InsufficientStock;
use App\Domain\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    #[Test]
    public function itReservesAvailableStock(): void
    {
        $product = new Product('Limited edition print', 10);

        $product->reserve(3);

        self::assertSame(7, $product->availableStock());
    }

    #[Test]
    public function itAllowsReservingAllAvailableStock(): void
    {
        $product = new Product('Concert ticket', 2);

        $product->reserve(2);

        self::assertSame(0, $product->availableStock());
    }

    #[Test]
    public function itRejectsAReservationWhenStockIsInsufficientWithoutChangingStock(): void
    {
        $product = new Product('Concert ticket', 2);

        try {
            $product->reserve(3);
            self::fail('An insufficient-stock exception was expected.');
        } catch (InsufficientStock $exception) {
            self::assertSame(3, $exception->requestedQuantity);
            self::assertSame(2, $exception->availableStock);
        }

        self::assertSame(2, $product->availableStock());
    }

    #[Test]
    public function itReleasesStock(): void
    {
        $product = new Product('Concert ticket', 2);

        $product->release(3);

        self::assertSame(5, $product->availableStock());
    }

    #[Test]
    #[DataProvider('nonPositiveQuantities')]
    public function itRejectsNonPositiveStockOperations(int $quantity): void
    {
        $product = new Product('Concert ticket', 2);

        $this->expectException(\InvalidArgumentException::class);

        $product->reserve($quantity);
    }

    #[Test]
    public function itRejectsReleasingANonPositiveQuantity(): void
    {
        $product = new Product('Concert ticket', 2);

        $this->expectException(\InvalidArgumentException::class);

        $product->release(0);
    }

    #[Test]
    #[DataProvider('invalidProductDefinitions')]
    public function itRejectsAnInvalidProductDefinition(string $name, int $availableStock): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Product($name, $availableStock);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidProductDefinitions(): iterable
    {
        yield 'blank name' => ['   ', 1];
        yield 'name too long' => [str_repeat('a', Product::MAX_NAME_LENGTH + 1), 1];
        yield 'negative stock' => ['Concert ticket', -1];
    }
}
