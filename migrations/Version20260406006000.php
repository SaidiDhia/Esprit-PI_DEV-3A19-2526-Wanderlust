<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406006000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cart and cart_item tables for shopping cart functionality.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Create cart table
        if (!$this->tableExists('cart')) {
            $this->addSql(<<<'SQL'
CREATE TABLE cart (
    id INT AUTO_INCREMENT NOT NULL,
    user_id CHAR(36) NOT NULL,
    total_price NUMERIC(10, 2) NOT NULL DEFAULT '0.00',
    PRIMARY KEY (id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
        }

        // Create cart_item table
        if (!$this->tableExists('cart_item')) {
            $this->addSql(<<<'SQL'
CREATE TABLE cart_item (
    id INT AUTO_INCREMENT NOT NULL,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_cart (cart_id),
    INDEX idx_product (product_id),
    FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('cart_item')) {
            $this->addSql('DROP TABLE cart_item');
        }

        if ($this->tableExists('cart')) {
            $this->addSql('DROP TABLE cart');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            ['table' => $table]
        );
    }
}
