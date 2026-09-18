<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class FunctionalDatabaseTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime(new \DateTimeImmutable('2026-01-15 10:05:00', new \DateTimeZone('UTC')));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $databaseName = $this->connection->getDatabase();
        self::assertNotNull($databaseName);
        self::assertStringContainsString(
            '_test',
            $databaseName,
            'Functional tests must never clean the development database.',
        );

        $this->cleanDatabase();
    }

    #[\Override]
    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->entityManager->clear();
        $this->cleanDatabase();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseData(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return self::associativeArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function associativeArray(mixed $value): array
    {
        self::assertIsArray($value);

        foreach (array_keys($value) as $key) {
            self::assertIsString($key);
        }

        /** @var array<string, mixed> $associativeArray */
        $associativeArray = $value;

        return $associativeArray;
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
}
