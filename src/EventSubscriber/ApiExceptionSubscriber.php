<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Domain\Exception\InsufficientStock;
use App\Domain\Exception\InvalidReservationQuantity;
use App\Domain\Exception\InvalidReservationUserId;
use App\Domain\Exception\ReservationCannotBeConfirmed;
use App\Domain\Exception\ReservationCannotBeExpired;
use App\Dto\ProblemResponse;
use App\Repository\Exception\ProductNotFound;
use App\Repository\Exception\ReservationNotFound;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!self::isApiRequest($request)) {
            return;
        }

        $problem = $this->createProblem($event->getThrowable(), $request);
        $event->setResponse(new JsonResponse(
            $problem->toArray(),
            $problem->status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function createProblem(\Throwable $exception, Request $request): ProblemResponse
    {
        $validationException = self::findValidationException($exception);

        if (null !== $validationException) {
            return new ProblemResponse(
                type: 'urn:problem:validation-failed',
                title: 'Validation failed',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detail: 'The request payload contains validation errors.',
                code: 'validation_failed',
                instance: $request->getRequestUri(),
                violations: self::violations($validationException),
            );
        }

        [$status, $title, $code, $detail] = match (true) {
            $exception instanceof ProductNotFound => [
                Response::HTTP_NOT_FOUND,
                'Product not found',
                'product_not_found',
                $exception->getMessage(),
            ],
            $exception instanceof ReservationNotFound => [
                Response::HTTP_NOT_FOUND,
                'Reservation not found',
                'reservation_not_found',
                $exception->getMessage(),
            ],
            $exception instanceof InsufficientStock => [
                Response::HTTP_CONFLICT,
                'Insufficient stock',
                'insufficient_stock',
                $exception->getMessage(),
            ],
            $exception instanceof ReservationCannotBeConfirmed => [
                Response::HTTP_CONFLICT,
                'Reservation cannot be confirmed',
                'reservation_cannot_be_confirmed',
                $exception->getMessage(),
            ],
            $exception instanceof ReservationCannotBeExpired => [
                Response::HTTP_CONFLICT,
                'Reservation cannot be expired',
                'reservation_cannot_be_expired',
                $exception->getMessage(),
            ],
            $exception instanceof InvalidReservationQuantity,
            $exception instanceof InvalidReservationUserId => [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Invalid reservation',
                'invalid_reservation',
                $exception->getMessage(),
            ],
            $exception instanceof BadRequestHttpException
                && $exception->getPrevious() instanceof NotEncodableValueException => [
                    Response::HTTP_BAD_REQUEST,
                    'Invalid JSON',
                    'invalid_json',
                    'The request body contains malformed JSON.',
                ],
            $exception instanceof ExtraAttributesException => [
                Response::HTTP_BAD_REQUEST,
                'Invalid request',
                'invalid_request',
                $exception->getMessage(),
            ],
            $exception instanceof HttpExceptionInterface => self::httpProblem($exception),
            default => $this->unexpectedProblem($exception),
        };

        return new ProblemResponse(
            type: sprintf('urn:problem:%s', str_replace('_', '-', $code)),
            title: $title,
            status: $status,
            detail: $detail,
            code: $code,
            instance: $request->getRequestUri(),
        );
    }

    /**
     * @return array{int, string, string, string}
     */
    private static function httpProblem(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();
        $title = Response::$statusTexts[$status] ?? 'Request failed';
        $code = match ($status) {
            Response::HTTP_BAD_REQUEST => 'invalid_request',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
            default => 'http_error',
        };

        return [$status, $title, $code, '' !== $exception->getMessage() ? $exception->getMessage() : $title];
    }

    /**
     * @return array{int, string, string, string}
     */
    private function unexpectedProblem(\Throwable $exception): array
    {
        $this->logger->error('Unhandled {exceptionClass} while processing an API request: {exceptionMessage}', [
            'exceptionClass' => $exception::class,
            'exceptionMessage' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        return [
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'Internal Server Error',
            'internal_error',
            'An unexpected error occurred.',
        ];
    }

    private static function findValidationException(\Throwable $exception): ?ValidationFailedException
    {
        do {
            if ($exception instanceof ValidationFailedException) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        } while (null !== $exception);

        return null;
    }

    /**
     * @return list<array{propertyPath: string, message: string}>
     */
    private static function violations(ValidationFailedException $exception): array
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $violations[] = [
                'propertyPath' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        usort(
            $violations,
            static fn (array $left, array $right): int => [$left['propertyPath'], $left['message']]
                <=> [$right['propertyPath'], $right['message']],
        );

        return $violations;
    }

    private static function isApiRequest(Request $request): bool
    {
        return '/api' === $request->getPathInfo()
            || str_starts_with($request->getPathInfo(), '/api/');
    }
}
