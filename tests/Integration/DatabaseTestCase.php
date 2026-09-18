<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-import-type Params from DriverManager
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;
    protected Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $databaseName = $this->connection->getDatabase();
        self::assertNotNull($databaseName);
        self::assertStringContainsString(
            '_test',
            $databaseName,
            'Integration tests must never clean the development database.',
        );

        $this->cleanDatabase();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->rollBackActiveTransactions();
        $this->entityManager->clear();
        $this->cleanDatabase();

        parent::tearDown();
    }

    protected function createCompetingConnection(): Connection
    {
        /** @var Params $connectionParameters */
        $connectionParameters = $this->connection->getParams();

        return DriverManager::getConnection($connectionParameters);
    }

    protected static function databaseInteger(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Expected the database to return an integer value.');
        }

        return (int) $value;
    }

    protected static function databaseString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Expected the database to return a string value.');
        }

        return $value;
    }

    private function cleanDatabase(): void
    {
        $this->connection->executeStatement('DELETE FROM reservations');
        $this->connection->executeStatement('DELETE FROM products');
    }

    private function rollBackActiveTransactions(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }
}
