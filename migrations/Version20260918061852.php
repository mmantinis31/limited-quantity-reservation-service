<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918061852 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create products and reservations with stock, lifecycle, and temporal constraints.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                available_stock INT NOT NULL,
                CONSTRAINT chk_products_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0),
                CONSTRAINT chk_products_available_stock CHECK (available_stock >= 0),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reservations (
                id INT AUTO_INCREMENT NOT NULL,
                product_id INT NOT NULL,
                user_id VARCHAR(128) NOT NULL,
                quantity SMALLINT NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                expired_at DATETIME DEFAULT NULL,
                INDEX idx_reservations_expiration (status, expires_at, id),
                INDEX idx_reservations_product (product_id),
                CONSTRAINT chk_reservations_user_id_not_blank CHECK (CHAR_LENGTH(TRIM(user_id)) > 0),
                CONSTRAINT chk_reservations_quantity CHECK (quantity BETWEEN 1 AND 10),
                CONSTRAINT chk_reservations_status CHECK (status IN ('pending', 'confirmed', 'expired')),
                CONSTRAINT chk_reservations_expiration CHECK (expires_at > created_at),
                CONSTRAINT chk_reservations_status_timestamps CHECK (
                    (status = 'pending' AND confirmed_at IS NULL AND expired_at IS NULL)
                    OR (status = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_at < expires_at AND expired_at IS NULL)
                    OR (status = 'expired' AND confirmed_at IS NULL AND expired_at IS NOT NULL AND expired_at >= expires_at)
                ),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reservations
                ADD CONSTRAINT FK_4DA2394584665A
                FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('DROP TABLE reservations');
        $this->addSql('DROP TABLE products');
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }
}
