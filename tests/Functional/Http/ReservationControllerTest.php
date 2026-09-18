<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Domain\Product;
use App\Domain\Reservation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

final class ReservationControllerTest extends FunctionalDatabaseTestCase
{
    #[Test]
    public function itCreatesAReservation(): void
    {
        $product = $this->persistProduct(5);

        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => self::productId($product),
            'userId' => 'user-123',
            'quantity' => 2,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $response = $this->responseData();
        self::assertIsInt($response['id']);
        self::assertSame(self::productId($product), $response['productId']);
        self::assertSame('user-123', $response['userId']);
        self::assertSame(2, $response['quantity']);
        self::assertSame('pending', $response['status']);
        self::assertSame('2026-01-15T10:05:00+00:00', $response['createdAt']);
        self::assertSame('2026-01-15T10:20:00+00:00', $response['expiresAt']);
        self::assertNull($response['confirmedAt']);
        self::assertNull($response['expiredAt']);
        self::assertSame(3, $this->availableStock($product));
    }

    #[Test]
    public function itReturnsValidationErrorsAsProblemJson(): void
    {
        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => 0,
            'userId' => '   ',
            'quantity' => 11,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = $this->responseData();
        self::assertSame('urn:problem:validation-failed', $problem['type']);
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $problem['status']);
        self::assertSame('/api/reservations', $problem['instance']);
        self::assertSame(
            ['productId', 'quantity', 'userId'],
            array_column(self::violations($problem), 'propertyPath'),
        );
    }

    #[Test]
    public function itReportsAllMissingRequiredFields(): void
    {
        $this->client->jsonRequest('POST', '/api/reservations', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = $this->responseData();
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(
            ['productId', 'quantity', 'userId'],
            array_column(self::violations($problem), 'propertyPath'),
        );
    }

    #[Test]
    public function itReportsUnexpectedPropertyTypesAsValidationErrors(): void
    {
        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => 1,
            'userId' => 'user-123',
            'quantity' => 'two',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = $this->responseData();
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(
            ['quantity'],
            array_column(self::violations($problem), 'propertyPath'),
        );
    }

    #[Test]
    public function itRejectsMalformedJson(): void
    {
        $this->client->request(
            'POST',
            '/api/reservations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"productId":',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = $this->responseData();
        self::assertSame('invalid_json', $problem['code']);
        self::assertSame('The request body contains malformed JSON.', $problem['detail']);
    }

    #[Test]
    public function itRejectsUnknownRequestProperties(): void
    {
        $product = $this->persistProduct(5);

        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => self::productId($product),
            'userId' => 'user-123',
            'quantity' => 1,
            'quantitty' => 1,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('invalid_request', $this->responseData()['code']);
        self::assertSame(5, $this->availableStock($product));
    }

    #[Test]
    public function itRequiresAJsonContentTypeForCreation(): void
    {
        $this->client->request('POST', '/api/reservations', content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('unsupported_media_type', $this->responseData()['code']);
    }

    #[Test]
    public function itReturnsAProblemWhenTheProductDoesNotExist(): void
    {
        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => 999_999,
            'userId' => 'user-123',
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('product_not_found', $this->responseData()['code']);
    }

    #[Test]
    public function itReturnsAConflictWithoutChangingStateWhenStockIsInsufficient(): void
    {
        $product = $this->persistProduct(1);

        $this->client->jsonRequest('POST', '/api/reservations', [
            'productId' => self::productId($product),
            'userId' => 'user-123',
            'quantity' => 2,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('insufficient_stock', $this->responseData()['code']);
        self::assertSame(1, $this->availableStock($product));
        self::assertSame(0, self::databaseInteger(
            $this->connection->fetchOne('SELECT COUNT(*) FROM reservations'),
        ));
    }

    #[Test]
    public function itConfirmsAReservationIdempotently(): void
    {
        $product = $this->persistProduct(10);
        $reservation = $this->persistPendingReservation($product, '2026-01-15 10:00:00');
        $reservationId = self::reservationId($reservation);

        $this->client->request('POST', sprintf('/api/reservations/%d/confirm', $reservationId));

        self::assertResponseIsSuccessful();
        $firstResponse = $this->responseData();
        self::assertSame('confirmed', $firstResponse['status']);
        self::assertSame('2026-01-15T10:05:00+00:00', $firstResponse['confirmedAt']);

        $this->client->request('POST', sprintf('/api/reservations/%d/confirm', $reservationId));

        self::assertResponseIsSuccessful();
        $secondResponse = $this->responseData();
        self::assertSame($firstResponse, $secondResponse);
        self::assertSame(8, $this->availableStock($product));
    }

    #[Test]
    public function itRejectsConfirmationOfAnOverduePendingReservation(): void
    {
        $product = $this->persistProduct(10);
        $reservation = $this->persistPendingReservation($product, '2026-01-15 09:00:00');

        $this->client->request(
            'POST',
            sprintf('/api/reservations/%d/confirm', self::reservationId($reservation)),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('reservation_cannot_be_confirmed', $this->responseData()['code']);
        self::assertSame('pending', $this->persistedStatus($reservation));
        self::assertSame(8, $this->availableStock($product));
    }

    #[Test]
    public function itRejectsConfirmationOfAnExpiredReservation(): void
    {
        $product = $this->persistProduct(10);
        $reservation = $this->persistPendingReservation($product, '2026-01-15 09:00:00');
        self::assertTrue($reservation->expire($reservation->expiresAt()));
        $product->release($reservation->quantity());
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            sprintf('/api/reservations/%d/confirm', self::reservationId($reservation)),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('reservation_cannot_be_confirmed', $this->responseData()['code']);
        self::assertSame('expired', $this->persistedStatus($reservation));
        self::assertSame(10, $this->availableStock($product));
    }

    #[Test]
    public function itReturnsAProblemWhenTheReservationDoesNotExist(): void
    {
        $this->client->request('POST', '/api/reservations/999999/confirm');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('reservation_not_found', $this->responseData()['code']);
    }

    private function persistProduct(int $availableStock): Product
    {
        $product = new Product('Concert ticket', $availableStock);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function persistPendingReservation(Product $product, string $createdAt): Reservation
    {
        $product->reserve(2);
        $reservation = Reservation::create(
            $product,
            'user-123',
            2,
            new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
        );
        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
    }

    private function availableStock(Product $product): int
    {
        return self::databaseInteger($this->connection->fetchOne(
            'SELECT available_stock FROM products WHERE id = ?',
            [self::productId($product)],
        ));
    }

    private function persistedStatus(Reservation $reservation): string
    {
        return self::databaseString($this->connection->fetchOne(
            'SELECT status FROM reservations WHERE id = ?',
            [self::reservationId($reservation)],
        ));
    }

    /**
     * @param array<string, mixed> $problem
     *
     * @return list<array{propertyPath: string, message: string}>
     */
    private static function violations(array $problem): array
    {
        $violations = $problem['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertTrue(array_is_list($violations));

        $validatedViolations = [];

        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            self::assertArrayHasKey('propertyPath', $violation);
            self::assertIsString($violation['propertyPath']);
            self::assertArrayHasKey('message', $violation);
            self::assertIsString($violation['message']);

            $validatedViolations[] = [
                'propertyPath' => $violation['propertyPath'],
                'message' => $violation['message'],
            ];
        }

        return $validatedViolations;
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
}
