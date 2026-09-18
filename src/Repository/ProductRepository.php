<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Product;
use App\Repository\Exception\ProductNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function getForUpdate(int $productId): Product
    {
        $this->assertTransactionActive();

        $product = $this->find($productId, LockMode::PESSIMISTIC_WRITE);

        if (null === $product) {
            throw new ProductNotFound($productId);
        }

        return $product;
    }

    private function assertTransactionActive(): void
    {
        if (!$this->getEntityManager()->getConnection()->isTransactionActive()) {
            throw TransactionRequiredException::transactionRequired();
        }
    }
}
