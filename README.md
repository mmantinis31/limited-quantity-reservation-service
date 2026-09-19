# Limited-Quantity Reservation Service

A production-minded Symfony service for reserving limited-stock products without overselling. It exposes HTTP and CLI entry points for creating reservations, supports idempotent confirmation, and releases stock from reservations that were not confirmed within 15 minutes.

The implementation deliberately stays small: transport adapters call shared application use cases, domain entities enforce business rules, and MySQL transactions with row-level locks protect consistency under concurrent requests.

## Contents

- [Business rules](#business-rules)
- [Technology](#technology)
- [Quick start](#quick-start)
- [API documentation](#api-documentation)
- [HTTP API](#http-api)
- [Console commands](#console-commands)
- [Architecture](#architecture)
- [Concurrency and consistency](#concurrency-and-consistency)
- [Tests and quality checks](#tests-and-quality-checks)
- [Database and fixtures](#database-and-fixtures)

## Business rules

- A reservation quantity must be between **1 and 10** units. The upper limit prevents accidental or abusive bulk reservations while keeping the policy explicit in the domain.
- Product stock represents the quantity currently available for reservation.
- Creating a reservation immediately decreases available stock.
- A new reservation is `pending` and expires exactly **15 minutes** after creation.
- A pending reservation may transition to either `confirmed` or `expired`.
- Confirmation is allowed only before the expiration boundary. At `now >= expiresAt`, confirmation is rejected.
- Confirming an already confirmed reservation is idempotent and returns the existing confirmed reservation.
- Confirmation does not change stock because it was already held during creation.
- Expiration releases the reserved quantity exactly once.
- All business timestamps are normalized and returned in UTC.

Reservation lifecycle:

```text
             confirm before expiresAt
PENDING  ------------------------------>  CONFIRMED
   |
   | expire at or after expiresAt
   v
EXPIRED
```

## Technology

- PHP 8.4
- Symfony 7.4 LTS
- MySQL 8.4 / InnoDB
- Doctrine ORM, DBAL, Migrations, and Fixtures
- NelmioApiDocBundle / OpenAPI 3
- PHPUnit 12
- PHPStan at maximum level with Doctrine, Symfony, PHPUnit, and strict-rules extensions
- PHP-CS-Fixer
- Docker Compose with PHP-FPM and Nginx

## Quick start

### Prerequisites

- Docker with Docker Compose v2
- Git

No host installation of PHP, Composer, MySQL, or Nginx is required.

### Start from a clean checkout

```bash
git clone <repository-url>
cd limited-quantity-reservation-service

docker compose up -d --build
docker compose exec php composer install --no-interaction
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

The fixture command purges application data before loading the sample products. Do not run it against data that must be preserved.

Verify that the containers are healthy:

```bash
docker compose ps
```

The application is now available at:

- Swagger UI: [http://localhost:8080/api/doc](http://localhost:8080/api/doc)
- OpenAPI JSON: [http://localhost:8080/api/doc.json](http://localhost:8080/api/doc.json)
- Application root: [http://localhost:8080](http://localhost:8080) — redirects to Swagger UI

Run the complete quality suite:

```bash
docker compose exec php composer qa
```

### Stop the application

Preserve database data:

```bash
docker compose down
```

Remove containers and all Docker-managed project data, including both databases:

```bash
docker compose down --volumes
```

The second command is destructive and cannot be used to recover deleted database data.

## API documentation

Interactive OpenAPI documentation is served at:

**[http://localhost:8080/api/doc](http://localhost:8080/api/doc)**

The machine-readable OpenAPI document is available at:

**[http://localhost:8080/api/doc.json](http://localhost:8080/api/doc.json)**

Swagger UI documents both endpoints, their request and response schemas, validation constraints, examples, and expected error responses.

## HTTP API

Requests with a body use `application/json`. API errors follow an RFC 7807-style `application/problem+json` representation and include a stable machine-readable `code`.

### Create a reservation

```http
POST /api/reservations
Content-Type: application/json
```

Request:

```json
{
  "productId": 1,
  "userId": "user-123",
  "quantity": 2
}
```

Example:

```bash
curl --request POST http://localhost:8080/api/reservations \
  --header 'Content-Type: application/json' \
  --data '{"productId":1,"userId":"user-123","quantity":2}'
```

Successful response: `201 Created`

```json
{
  "id": 1,
  "productId": 1,
  "userId": "user-123",
  "quantity": 2,
  "status": "pending",
  "createdAt": "2026-09-18T10:00:00+00:00",
  "expiresAt": "2026-09-18T10:15:00+00:00",
  "confirmedAt": null,
  "expiredAt": null
}
```

### Confirm a reservation

```http
POST /api/reservations/{id}/confirm
```

Example:

```bash
curl --request POST http://localhost:8080/api/reservations/1/confirm
```

Successful response: `200 OK`

The operation is idempotent: repeating it for an already confirmed reservation returns `200 OK`, preserves the original `confirmedAt`, and does not alter stock.

Confirmation fails with `409 Conflict` when the reservation is expired or its pending confirmation deadline has passed.

### Error responses

| Status | Meaning |
|---:|---|
| `400 Bad Request` | Malformed JSON, unknown request properties, or another invalid request representation |
| `404 Not Found` | Product, reservation, or route not found |
| `409 Conflict` | Insufficient stock or a reservation lifecycle conflict |
| `415 Unsupported Media Type` | Create request is not sent as JSON |
| `422 Unprocessable Entity` | Structurally valid JSON failed field validation |
| `500 Internal Server Error` | Unexpected server-side failure; internal details are not exposed |

Example problem response:

```json
{
  "type": "urn:problem:insufficient-stock",
  "title": "Insufficient stock",
  "status": 409,
  "detail": "Cannot reserve 2 unit(s); only 1 unit(s) are available.",
  "code": "insufficient_stock",
  "instance": "/api/reservations"
}
```

Validation responses additionally contain a deterministic `violations` array:

```json
{
  "type": "urn:problem:validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request payload contains validation errors.",
  "code": "validation_failed",
  "instance": "/api/reservations",
  "violations": [
    {
      "propertyPath": "quantity",
      "message": "Quantity must be between 1 and 10."
    }
  ]
}
```

## Console commands

The console adapters call the same application use cases as the HTTP endpoints; business rules are not duplicated in the commands.

### Create a reservation

```bash
docker compose exec php php bin/console app:reservation:create \
  <product-id> <user-id> <quantity>
```

Example:

```bash
docker compose exec php php bin/console app:reservation:create 1 user-123 2
```

The command displays the reservation ID, product ID, user ID, quantity, status, and expiration timestamp.

### Expire stale reservations

```bash
docker compose exec php php bin/console app:reservation:expire
```

The default batch size is 100. It may be configured between 1 and 1,000:

```bash
docker compose exec php php bin/console app:reservation:expire --batch-size=250
```

The command reports the number of expired reservations, released units, and processed batches. It is safe to run repeatedly and supports multiple concurrent workers through `FOR UPDATE SKIP LOCKED`.

## Architecture

The project uses a lightweight layered design:

```text
HTTP Controller / Console Command
                |
                v
        Application use case
                |
                v
      Domain model + Repository
                |
                v
          Doctrine / MySQL
```

```text
src/
|-- Domain/          Product, Reservation, lifecycle enum, domain exceptions
|-- Application/     Transactional create, confirm, and expiration use cases
|-- Repository/      Doctrine queries and explicit lock acquisition
|-- Controller/      HTTP transport adapter
|-- Command/         Console transport adapters
|-- Dto/             HTTP request, response, and problem representations
|-- EventSubscriber/ Consistent API exception mapping
`-- DataFixtures/    Sample products
```

Key design decisions:

- Doctrine entities are placed in `Domain` because they contain behaviour and protect business invariants; they are not passive persistence records.
- Controllers and console commands only translate transport input and output.
- Application services own transaction boundaries and coordinate repositories, time, and domain operations.
- `ClockInterface` makes expiration behaviour deterministic and testable.
- Entities are not serialized directly. Explicit DTOs keep the public API separate from persistence details.
- Framework-provided interfaces are used where they represent real boundaries. One-to-one project interfaces, generic repositories, CQRS, events, queues, and distributed locks are intentionally omitted because they do not solve a current requirement.

## Concurrency and consistency

Creating a reservation runs in one database transaction:

1. Lock the product row using a pessimistic write lock (`SELECT ... FOR UPDATE`).
2. Validate the request and available stock while holding that lock.
3. Decrease available stock through the domain model.
4. Persist the pending reservation.
5. Commit both changes atomically.

Concurrent requests for the same product therefore serialize at the product row. A request that waits for the lock observes the stock committed by the preceding request and cannot oversell it.

Confirmation locks the reservation row before evaluating its current state and expiration time. This prevents concurrent confirmation and expiration from producing a confirmed reservation whose stock was also released.

Expiration processing:

- selects only overdue `pending` reservations;
- processes bounded batches in separate transactions;
- uses `FOR UPDATE SKIP LOCKED`, allowing workers to claim disjoint batches;
- locks affected products in ascending ID order to reduce deadlock risk;
- aggregates released quantities per product;
- transitions reservation state and restores stock in the same transaction.

Database constraints provide defence in depth for non-negative stock, valid reservation quantities and statuses, expiration ordering, lifecycle timestamps, and product references.

## Tests and quality checks

Tests use a dedicated MySQL database named `reservation_test`. Docker creates it on the first MySQL volume initialization. `composer test` automatically applies pending migrations to that database before PHPUnit runs; it never migrates or cleans the development database.

Run all tests:

```bash
docker compose exec php composer test
```

Run the complete quality gate:

```bash
docker compose exec php composer qa
```

`composer qa` executes:

1. strict Composer validation;
2. PHP-CS-Fixer in dry-run mode;
3. PHPStan at maximum level;
4. test-database migrations;
5. PHPUnit.

Run individual checks:

```bash
docker compose exec php composer check:composer
docker compose exec php composer cs:check
docker compose exec php composer analyse
docker compose exec php composer test
docker compose exec php composer audit
```

Apply coding-style fixes:

```bash
docker compose exec php composer cs:fix
```

Validate Symfony and Doctrine configuration:

```bash
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console doctrine:schema:validate
```

Run only the real MySQL multi-process concurrency scenarios:

```bash
docker compose exec php composer test:prepare
docker compose exec php php bin/phpunit \
  tests/Integration/Application/ReservationConcurrencyTest.php
```

Test coverage is split by responsibility:

- **Unit:** domain invariants, quantities, lifecycle transitions, and time boundaries without Symfony or a database.
- **Integration:** repositories, transactions, rollback, pessimistic locks, batching, `SKIP LOCKED`, and application use cases against MySQL.
- **Functional:** HTTP responses, Problem Details, OpenAPI output, and console commands.
- **Concurrency:** separate PHP processes and database connections prove overselling prevention and confirm/expire race safety.

SQLite is intentionally not used because it cannot prove the MySQL locking semantics on which the implementation relies.

## Database and fixtures

Apply development migrations:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Load fixtures:

```bash
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

On a clean database, fixtures create:

| ID | Product | Available stock |
|---:|---|---:|
| 1 | Concert Ticket | 100 |
| 2 | Limited Edition Vinyl | 25 |
| 3 | Collector Box | 10 |

Fixture loading purges existing application data. The IDs above are only guaranteed on a freshly initialized database.

The host MySQL connection uses `localhost:33060` by default. Containers connect internally through `database:3306`.
