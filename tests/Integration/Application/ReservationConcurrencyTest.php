<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Domain\Exception\InsufficientStock;
use App\Domain\Exception\ReservationCannotBeConfirmed;
use App\Domain\Product;
use App\Domain\Reservation;
use App\Domain\ReservationStatus;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

final class ReservationConcurrencyTest extends DatabaseTestCase
{
    private const int WORKER_TIMEOUT_SECONDS = 15;
    private const int BARRIER_TIMEOUT_MICROSECONDS = 5_000_000;
    private const int BARRIER_POLL_INTERVAL_MICROSECONDS = 10_000;

    #[Test]
    public function concurrentReservationAttemptsCannotOversellTheLastUnit(): void
    {
        $product = new Product('Last concert ticket', 1);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $productId = self::productId($product);
        $this->entityManager->clear();

        $barrier = self::createBarrier(2);
        $workers = [];

        try {
            $this->connection->beginTransaction();

            try {
                $lockedProductId = $this->connection->fetchOne(
                    'SELECT id FROM products WHERE id = ? FOR UPDATE',
                    [$productId],
                );
                self::assertSame($productId, self::databaseInteger($lockedProductId));

                $workers[] = self::startWorker([
                    'operation' => 'create',
                    'now' => '2026-01-15 10:00:00',
                    'readyPath' => $barrier['readyPaths'][0],
                    'releasePath' => $barrier['releasePath'],
                    'productId' => $productId,
                    'userId' => 'concurrent-user-a',
                    'quantity' => 1,
                ]);
                $workers[] = self::startWorker([
                    'operation' => 'create',
                    'now' => '2026-01-15 10:00:00',
                    'readyPath' => $barrier['readyPaths'][1],
                    'releasePath' => $barrier['releasePath'],
                    'productId' => $productId,
                    'userId' => 'concurrent-user-b',
                    'quantity' => 1,
                ]);

                self::waitUntilWorkersAreReady($workers, $barrier['readyPaths']);
                self::releaseWorkers($barrier['releasePath']);

                // The parent transaction keeps both workers in flight at the same locked row.
                usleep(100_000);
                self::assertTrue($workers[0]->isRunning());
                self::assertTrue($workers[1]->isRunning());
            } finally {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            }

            $results = self::waitForWorkers($workers);

            $successfulResults = array_values(array_filter(
                $results,
                static fn (array $result): bool => true === ($result['ok'] ?? null),
            ));
            $failedResults = array_values(array_filter(
                $results,
                static fn (array $result): bool => false === ($result['ok'] ?? null),
            ));

            self::assertCount(1, $successfulResults);
            self::assertCount(1, $failedResults);
            self::assertSame(
                ReservationStatus::PENDING->value,
                self::workerResultData($successfulResults[0])['status'] ?? null,
            );
            self::assertSame(InsufficientStock::class, $failedResults[0]['exception'] ?? null);
            self::assertSame(0, self::databaseInteger($this->connection->fetchOne(
                'SELECT available_stock FROM products WHERE id = ?',
                [$productId],
            )));
            self::assertSame(1, self::databaseInteger($this->connection->fetchOne(
                'SELECT COUNT(*) FROM reservations WHERE product_id = ?',
                [$productId],
            )));
        } finally {
            self::stopWorkers($workers);
            self::removeBarrier($barrier);
        }
    }

    #[Test]
    public function concurrentConfirmationAndExpirationPreserveReservationAndStockConsistency(): void
    {
        $product = new Product('Limited vinyl', 10);
        $product->reserve(2);
        $reservation = Reservation::create(
            $product,
            'race-user',
            2,
            self::utc('2026-01-15 10:00:00'),
        );
        $this->entityManager->persist($product);
        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        $productId = self::productId($product);
        $reservationId = self::reservationId($reservation);
        $this->entityManager->clear();

        $barrier = self::createBarrier(2);
        $workers = [];

        try {
            $workers[] = self::startWorker([
                'operation' => 'confirm',
                'now' => '2026-01-15 10:14:59',
                'readyPath' => $barrier['readyPaths'][0],
                'releasePath' => $barrier['releasePath'],
                'reservationId' => $reservationId,
            ]);
            $workers[] = self::startWorker([
                'operation' => 'expire',
                'now' => '2026-01-15 10:15:00',
                'readyPath' => $barrier['readyPaths'][1],
                'releasePath' => $barrier['releasePath'],
                'batchSize' => 10,
            ]);

            self::waitUntilWorkersAreReady($workers, $barrier['readyPaths']);
            self::releaseWorkers($barrier['releasePath']);

            [$confirmationResult, $expirationResult] = self::waitForWorkers($workers);

            self::assertTrue($expirationResult['ok'] ?? false, self::workerFailureMessage($expirationResult));
            $expirationData = self::workerResultData($expirationResult);

            $status = self::databaseString($this->connection->fetchOne(
                'SELECT status FROM reservations WHERE id = ?',
                [$reservationId],
            ));
            $availableStock = self::databaseInteger($this->connection->fetchOne(
                'SELECT available_stock FROM products WHERE id = ?',
                [$productId],
            ));
            $confirmationWon = ReservationStatus::CONFIRMED->value === $status;

            self::assertContains($status, [
                ReservationStatus::CONFIRMED->value,
                ReservationStatus::EXPIRED->value,
            ]);
            self::assertSame($confirmationWon, $confirmationResult['ok'] ?? false);
            self::assertSame(
                $confirmationWon ? null : ReservationCannotBeConfirmed::class,
                $confirmationResult['exception'] ?? null,
            );
            self::assertSame($confirmationWon ? 0 : 1, $expirationData['expiredReservations'] ?? null);
            self::assertSame($confirmationWon ? 0 : 2, $expirationData['releasedUnits'] ?? null);
            self::assertSame($confirmationWon ? 8 : 10, $availableStock);
        } finally {
            self::stopWorkers($workers);
            self::removeBarrier($barrier);
        }
    }

    /**
     * @param array<string, int|string> $input
     */
    private static function startWorker(array $input): Process
    {
        $process = new Process(
            [
                PHP_BINARY,
                dirname(__DIR__, 2).'/concurrency-worker.php',
                json_encode($input, JSON_THROW_ON_ERROR),
            ],
            dirname(__DIR__, 3),
            [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
            ],
        );
        $process->setTimeout(self::WORKER_TIMEOUT_SECONDS);
        $process->start();

        return $process;
    }

    /**
     * @param list<Process> $workers
     * @param list<string>  $readyPaths
     */
    private static function waitUntilWorkersAreReady(array $workers, array $readyPaths): void
    {
        $deadline = hrtime(true) + self::BARRIER_TIMEOUT_MICROSECONDS * 1_000;

        while (true) {
            $readyWorkers = 0;

            foreach ($readyPaths as $index => $readyPath) {
                if (is_file($readyPath)) {
                    ++$readyWorkers;

                    continue;
                }

                if (!$workers[$index]->isRunning()) {
                    self::fail(sprintf(
                        "Concurrency worker stopped before reaching the barrier.\nSTDOUT: %s\nSTDERR: %s",
                        $workers[$index]->getOutput(),
                        $workers[$index]->getErrorOutput(),
                    ));
                }
            }

            if (count($workers) === $readyWorkers) {
                return;
            }

            if (hrtime(true) >= $deadline) {
                self::fail('Timed out while waiting for concurrency workers to reach the barrier.');
            }

            usleep(self::BARRIER_POLL_INTERVAL_MICROSECONDS);
        }
    }

    private static function releaseWorkers(string $releasePath): void
    {
        self::assertNotFalse(file_put_contents($releasePath, 'go', LOCK_EX));
    }

    /**
     * @param list<Process> $workers
     *
     * @return list<array<string, mixed>>
     */
    private static function waitForWorkers(array $workers): array
    {
        $results = [];

        foreach ($workers as $worker) {
            $worker->wait();
            $results[] = self::decodeWorkerOutput($worker);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeWorkerOutput(Process $worker): array
    {
        $output = trim($worker->getOutput());

        self::assertNotSame('', $output, sprintf('Worker produced no JSON output. STDERR: %s', $worker->getErrorOutput()));

        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return self::normalizeStringKeyedArray($result);
    }

    /**
     * @param array<string, mixed> $workerOutput
     *
     * @return array<string, mixed>
     */
    private static function workerResultData(array $workerOutput): array
    {
        $result = $workerOutput['result'] ?? null;
        self::assertIsArray($result);

        return self::normalizeStringKeyedArray($result);
    }

    /**
     * @param array<mixed, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function normalizeStringKeyedArray(array $values): array
    {
        $normalizedValues = [];

        foreach ($values as $key => $value) {
            self::assertIsString($key);
            $normalizedValues[$key] = $value;
        }

        return $normalizedValues;
    }

    /**
     * @param list<Process> $workers
     */
    private static function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1.0);
            }
        }
    }

    /**
     * @return array{directory: string, releasePath: string, readyPaths: list<string>}
     */
    private static function createBarrier(int $workerCount): array
    {
        $directory = sprintf(
            '%s/reservation-concurrency-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(12)),
        );

        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException(sprintf('Unable to create concurrency barrier directory "%s".', $directory));
        }

        $readyPaths = [];

        for ($worker = 0; $worker < $workerCount; ++$worker) {
            $readyPaths[] = sprintf('%s/worker-%d.ready', $directory, $worker);
        }

        return [
            'directory' => $directory,
            'releasePath' => $directory.'/release',
            'readyPaths' => $readyPaths,
        ];
    }

    /**
     * @param array{directory: string, releasePath: string, readyPaths: list<string>} $barrier
     */
    private static function removeBarrier(array $barrier): void
    {
        foreach ([...$barrier['readyPaths'], $barrier['releasePath']] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to remove concurrency barrier marker "%s".', $path));
            }
        }

        if (is_dir($barrier['directory']) && !rmdir($barrier['directory'])) {
            throw new \RuntimeException(sprintf('Unable to remove concurrency barrier directory "%s".', $barrier['directory']));
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function workerFailureMessage(array $result): string
    {
        $message = $result['message'] ?? 'unknown worker failure';

        return is_string($message) ? $message : 'unknown worker failure';
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

    private static function utc(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
    }
}
