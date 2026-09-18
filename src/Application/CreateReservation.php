<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Reservation;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateReservation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductRepository $products,
        private ClockInterface $clock,
    ) {
    }

    public function execute(int $productId, string $userId, int $quantity): Reservation
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($productId, $userId, $quantity): Reservation {
            $product = $this->products->getForUpdate($productId);
            $reservation = Reservation::create(
                $product,
                $userId,
                $quantity,
                $this->clock->now(),
            );

            $product->reserve($quantity);
            $entityManager->persist($reservation);

            return $reservation;
        });
    }
}
