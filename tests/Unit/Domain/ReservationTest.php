<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Exception\InvalidReservationQuantity;
use App\Domain\Exception\InvalidReservationUserId;
use App\Domain\Exception\ReservationCannotBeConfirmed;
use App\Domain\Exception\ReservationCannotBeExpired;
use App\Domain\Product;
use App\Domain\Reservation;
use App\Domain\ReservationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReservationTest extends TestCase
{
    #[Test]
    public function itCreatesAPendingReservationForFifteenMinutesInUtc(): void
    {
        $product = new Product('Concert ticket', 10);
        $createdAt = new \DateTimeImmutable('2026-01-15 12:00:00.987654', new \DateTimeZone('Europe/Vilnius'));

        $reservation = Reservation::create($product, 'user-123', 2, $createdAt);

        self::assertNull($reservation->id());
        self::assertSame($product, $reservation->product());
        self::assertSame('user-123', $reservation->userId());
        self::assertSame(2, $reservation->quantity());
        self::assertSame(ReservationStatus::PENDING, $reservation->status());
        self::assertSame('2026-01-15T10:00:00+00:00', $reservation->createdAt()->format(DATE_ATOM));
        self::assertSame('2026-01-15T10:15:00+00:00', $reservation->expiresAt()->format(DATE_ATOM));
        self::assertSame('000000', $reservation->createdAt()->format('u'));
        self::assertNull($reservation->confirmedAt());
        self::assertNull($reservation->expiredAt());
    }

    #[Test]
    #[DataProvider('invalidQuantities')]
    public function itRejectsAnInvalidQuantity(int $quantity): void
    {
        $this->expectException(InvalidReservationQuantity::class);

        Reservation::create(new Product('Concert ticket', 10), 'user-123', $quantity, self::createdAt());
    }

    #[Test]
    #[DataProvider('invalidUserIds')]
    public function itRejectsAnInvalidUserId(string $userId): void
    {
        $this->expectException(InvalidReservationUserId::class);

        Reservation::create(new Product('Concert ticket', 10), $userId, 1, self::createdAt());
    }

    #[Test]
    public function itConfirmsAPendingReservationBeforeExpiration(): void
    {
        $reservation = self::pendingReservation();
        $confirmedAt = self::createdAt()->modify('+14 minutes 59 seconds');

        $transitioned = $reservation->confirm($confirmedAt);

        self::assertTrue($transitioned);
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->status());
        self::assertEquals($confirmedAt, $reservation->confirmedAt());
        self::assertNull($reservation->expiredAt());
    }

    #[Test]
    public function confirmingAnAlreadyConfirmedReservationIsIdempotent(): void
    {
        $reservation = self::pendingReservation();
        $firstConfirmation = self::createdAt()->modify('+5 minutes');

        self::assertTrue($reservation->confirm($firstConfirmation));
        self::assertFalse($reservation->confirm(self::createdAt()->modify('+6 minutes')));

        self::assertEquals($firstConfirmation, $reservation->confirmedAt());
    }

    #[Test]
    #[DataProvider('expirationBoundaryAndLater')]
    public function itRejectsConfirmationAtOrAfterExpiration(string $confirmationTime): void
    {
        $reservation = self::pendingReservation();

        try {
            $reservation->confirm(new \DateTimeImmutable($confirmationTime, new \DateTimeZone('UTC')));
            self::fail('An expired reservation must not be confirmed.');
        } catch (ReservationCannotBeConfirmed $caughtException) {
            self::assertStringContainsString('expired at', $caughtException->getMessage());
        }

        self::assertSame(ReservationStatus::PENDING, $reservation->status());
        self::assertNull($reservation->confirmedAt());
    }

    #[Test]
    #[DataProvider('expirationBoundaryAndLater')]
    public function itExpiresAPendingReservationAtOrAfterExpiration(string $expirationTime): void
    {
        $reservation = self::pendingReservation();
        $expiredAt = new \DateTimeImmutable($expirationTime, new \DateTimeZone('UTC'));

        $transitioned = $reservation->expire($expiredAt);

        self::assertTrue($transitioned);
        self::assertSame(ReservationStatus::EXPIRED, $reservation->status());
        self::assertEquals($expiredAt, $reservation->expiredAt());
        self::assertNull($reservation->confirmedAt());
    }

    #[Test]
    public function itRejectsExpirationBeforeTheDeadline(): void
    {
        $reservation = self::pendingReservation();

        $this->expectException(ReservationCannotBeExpired::class);

        $reservation->expire(self::createdAt()->modify('+14 minutes 59 seconds'));
    }

    #[Test]
    public function expiringAnAlreadyExpiredReservationIsIdempotent(): void
    {
        $reservation = self::pendingReservation();
        $firstExpiration = self::createdAt()->modify('+15 minutes');

        self::assertTrue($reservation->expire($firstExpiration));
        self::assertFalse($reservation->expire(self::createdAt()->modify('+16 minutes')));

        self::assertEquals($firstExpiration, $reservation->expiredAt());
    }

    #[Test]
    public function itDoesNotExpireAConfirmedReservation(): void
    {
        $reservation = self::pendingReservation();
        $reservation->confirm(self::createdAt()->modify('+5 minutes'));

        $this->expectException(ReservationCannotBeExpired::class);

        $reservation->expire(self::createdAt()->modify('+15 minutes'));
    }

    #[Test]
    public function itDoesNotConfirmAnExpiredReservation(): void
    {
        $reservation = self::pendingReservation();
        $reservation->expire(self::createdAt()->modify('+15 minutes'));

        $this->expectException(ReservationCannotBeConfirmed::class);

        $reservation->confirm(self::createdAt()->modify('+16 minutes'));
    }

    #[Test]
    public function itReportsExpirationOnlyForExpiredOrOverduePendingReservations(): void
    {
        $pending = self::pendingReservation();
        $confirmed = self::pendingReservation();
        $confirmed->confirm(self::createdAt()->modify('+5 minutes'));

        self::assertFalse($pending->isExpiredAt(self::createdAt()->modify('+14 minutes 59 seconds')));
        self::assertTrue($pending->isExpiredAt(self::createdAt()->modify('+15 minutes')));
        self::assertFalse($confirmed->isExpiredAt(self::createdAt()->modify('+20 minutes')));

        $pending->expire(self::createdAt()->modify('+15 minutes'));

        self::assertTrue($pending->isExpiredAt(self::createdAt()->modify('+15 minutes')));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'above maximum' => [Reservation::MAX_QUANTITY + 1];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUserIds(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('u', Reservation::USER_ID_MAX_LENGTH + 1)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expirationBoundaryAndLater(): iterable
    {
        yield 'at expiration' => ['2026-01-15 10:15:00'];
        yield 'after expiration' => ['2026-01-15 10:16:00'];
    }

    private static function pendingReservation(): Reservation
    {
        return Reservation::create(new Product('Concert ticket', 10), 'user-123', 2, self::createdAt());
    }

    private static function createdAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
    }
}
