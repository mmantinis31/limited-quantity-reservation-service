<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\ConfirmReservation;
use App\Application\CreateReservation;
use App\Application\ExpireReservations;
use App\Kernel;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boots an isolated test kernel so concurrency tests use separate PHP processes,
 * Doctrine entity managers and database connections.
 */
final class ConcurrencyWorker
{
    private const int SUCCESS = 0;
    private const int FAILURE = 1;
    private const int BARRIER_TIMEOUT_MICROSECONDS = 10_000_000;
    private const int BARRIER_POLL_INTERVAL_MICROSECONDS = 10_000;

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $kernel = null;

        try {
            $input = self::decodeInput($arguments[1] ?? null);
            Clock::set(new MockClock(self::stringValue($input, 'now'), 'UTC'));

            $kernel = new Kernel('test', false);
            $kernel->boot();
            $container = self::testContainer($kernel);

            self::signalReady(self::stringValue($input, 'readyPath'));
            self::waitForRelease(self::stringValue($input, 'releasePath'));

            $result = match (self::stringValue($input, 'operation')) {
                'create' => self::createReservation($container, $input),
                'confirm' => self::confirmReservation($container, $input),
                'expire' => self::expireReservations($container, $input),
                default => throw new \InvalidArgumentException('Unsupported concurrency worker operation.'),
            };

            self::writeOutput([
                'ok' => true,
                'result' => $result,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            self::writeOutput([
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        } finally {
            $kernel?->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{reservationId: int, status: string}
     */
    private static function createReservation(ContainerInterface $container, array $input): array
    {
        $useCase = $container->get(CreateReservation::class);

        if (!$useCase instanceof CreateReservation) {
            throw new \LogicException('CreateReservation service is unavailable in the test container.');
        }

        $reservation = $useCase->execute(
            self::integerValue($input, 'productId'),
            self::stringValue($input, 'userId'),
            self::integerValue($input, 'quantity'),
        );

        return [
            'reservationId' => $reservation->id()
                ?? throw new \LogicException('A created reservation must have an identifier.'),
            'status' => $reservation->status()->value,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{reservationId: int, status: string}
     */
    private static function confirmReservation(ContainerInterface $container, array $input): array
    {
        $useCase = $container->get(ConfirmReservation::class);

        if (!$useCase instanceof ConfirmReservation) {
            throw new \LogicException('ConfirmReservation service is unavailable in the test container.');
        }

        $reservation = $useCase->execute(self::integerValue($input, 'reservationId'));

        return [
            'reservationId' => $reservation->id()
                ?? throw new \LogicException('A confirmed reservation must have an identifier.'),
            'status' => $reservation->status()->value,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{expiredReservations: int, releasedUnits: int, processedBatches: int}
     */
    private static function expireReservations(ContainerInterface $container, array $input): array
    {
        $useCase = $container->get(ExpireReservations::class);

        if (!$useCase instanceof ExpireReservations) {
            throw new \LogicException('ExpireReservations service is unavailable in the test container.');
        }

        $result = $useCase->execute(self::integerValue($input, 'batchSize'));

        return [
            'expiredReservations' => $result->expiredReservations,
            'releasedUnits' => $result->releasedUnits,
            'processedBatches' => $result->processedBatches,
        ];
    }

    private static function testContainer(KernelInterface $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');

        if (!$container instanceof ContainerInterface) {
            throw new \LogicException('Symfony test service container is unavailable.');
        }

        return $container;
    }

    private static function signalReady(string $readyPath): void
    {
        if (false === file_put_contents($readyPath, 'ready', LOCK_EX)) {
            throw new \RuntimeException(sprintf('Unable to create worker readiness marker "%s".', $readyPath));
        }
    }

    private static function waitForRelease(string $releasePath): void
    {
        $deadline = hrtime(true) + self::BARRIER_TIMEOUT_MICROSECONDS * 1_000;

        while (!is_file($releasePath)) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out while waiting for the concurrency test barrier.');
            }

            usleep(self::BARRIER_POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeInput(?string $encodedInput): array
    {
        if (null === $encodedInput) {
            throw new \InvalidArgumentException('Concurrency worker input is missing.');
        }

        $input = json_decode($encodedInput, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($input)) {
            throw new \InvalidArgumentException('Concurrency worker input must be a JSON object.');
        }

        $normalizedInput = [];

        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Concurrency worker input must be a JSON object.');
            }

            $normalizedInput[$key] = $value;
        }

        return $normalizedInput;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function stringValue(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('Concurrency worker input "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function integerValue(array $input, string $key): int
    {
        $value = $input[$key] ?? null;

        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Concurrency worker input "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $output
     */
    private static function writeOutput(array $output): void
    {
        fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
