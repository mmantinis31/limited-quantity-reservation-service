<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Domain\Product;
use App\Repository\Exception\ProductNotFound;
use App\Repository\ProductRepository;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\ORM\TransactionRequiredException;
use PHPUnit\Framework\Attributes\Test;

final class ProductRepositoryTest extends DatabaseTestCase
{
    private ProductRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $repository);
        $this->repository = $repository;
    }

    #[Test]
    public function itRequiresAnActiveTransactionBeforeLocking(): void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->repository->getForUpdate(1);
    }

    #[Test]
    public function itReturnsTheProductAndHoldsAnExclusiveDatabaseLock(): void
    {
        $product = $this->persistProduct();
        $productId = self::productId($product);
        $this->entityManager->clear();

        $this->connection->beginTransaction();

        $lockedProduct = $this->repository->getForUpdate($productId);

        self::assertSame($productId, $lockedProduct->id());

        $competingConnection = $this->createCompetingConnection();
        $competingConnection->beginTransaction();

        try {
            $competingResult = $competingConnection->createQueryBuilder()
                ->select('id')
                ->from('products')
                ->where('id = :id')
                ->setParameter('id', $productId, ParameterType::INTEGER)
                ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
                ->executeQuery()
                ->fetchOne();

            self::assertFalse($competingResult);
        } finally {
            $competingConnection->rollBack();
            $competingConnection->close();
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function itReportsAMissingProduct(): void
    {
        $this->connection->beginTransaction();

        $this->expectException(ProductNotFound::class);
        $this->expectExceptionMessage('Product with ID 999 was not found.');

        $this->repository->getForUpdate(999);
    }

    private function persistProduct(): Product
    {
        $product = new Product('Concert ticket', 10);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private static function productId(Product $product): int
    {
        $productId = $product->id();
        self::assertNotNull($productId);

        return $productId;
    }
}
