<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\InsufficientStock;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
class Product
{
    public const int MAX_NAME_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(name: 'available_stock', type: Types::INTEGER)]
    private int $availableStock;

    public function __construct(string $name, int $availableStock)
    {
        if ('' === trim($name) || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Product name must not be blank and must not exceed %d characters.', self::MAX_NAME_LENGTH));
        }

        if ($availableStock < 0) {
            throw new \InvalidArgumentException('Product available stock cannot be negative.');
        }

        $this->name = $name;
        $this->availableStock = $availableStock;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function availableStock(): int
    {
        return $this->availableStock;
    }

    public function reserve(int $quantity): void
    {
        self::assertPositiveQuantity($quantity);

        if ($quantity > $this->availableStock) {
            throw new InsufficientStock($quantity, $this->availableStock);
        }

        $this->availableStock -= $quantity;
    }

    public function release(int $quantity): void
    {
        self::assertPositiveQuantity($quantity);

        $this->availableStock += $quantity;
    }

    private static function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Stock quantity must be a positive integer.');
        }
    }
}
