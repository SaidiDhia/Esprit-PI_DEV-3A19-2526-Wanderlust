<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace tables: products, facture, facture_product, and delivery_address.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Create products table
        if (!$this->tableExists('products')) {
            $this->addSql(<<<'SQL'
CREATE TABLE products (
    id INT AUTO_INCREMENT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    type VARCHAR(100) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    price NUMERIC(10, 2) NOT NULL,
    quantity INT NOT NULL,
    reserved_quantity INT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    userId CHAR(36) NOT NULL,
    created_date DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_userId (userId),
    INDEX idx_category (category),
    INDEX idx_created_date (created_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
        }

        // Create facture table
        if (!$this->tableExists('facture')) {
            $this->addSql(<<<'SQL'
CREATE TABLE facture (
    id_facture INT AUTO_INCREMENT NOT NULL,
    date_facture DATETIME NOT NULL,
    total_price NUMERIC(10, 2) NOT NULL,
    delivery_status VARCHAR(50) DEFAULT NULL,
    PRIMARY KEY (id_facture),
    INDEX idx_date (date_facture),
    INDEX idx_status (delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
        }

        // Create facture_product table
        if (!$this->tableExists('facture_product')) {
            $this->addSql(<<<'SQL'
CREATE TABLE facture_product (
    id INT AUTO_INCREMENT NOT NULL,
    facture_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price NUMERIC(10, 2) NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_facture (facture_id),
    INDEX idx_product (product_id),
    FOREIGN KEY (facture_id) REFERENCES facture (id_facture) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
        }

        // Create delivery_address table
        if (!$this->tableExists('delivery_address')) {
            $this->addSql(<<<'SQL'
CREATE TABLE delivery_address (
    id INT AUTO_INCREMENT NOT NULL,
    facture_id INT NOT NULL,
    full_name VARCHAR(255) DEFAULT NULL,
    city VARCHAR(255) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    country VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_facture (facture_id),
    FOREIGN KEY (facture_id) REFERENCES facture (id_facture) ON DELETE CASCADE
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

        // Drop tables in reverse order of creation (respecting foreign keys)
        if ($this->tableExists('delivery_address')) {
            $this->addSql('DROP TABLE delivery_address');
        }

        if ($this->tableExists('facture_product')) {
            $this->addSql('DROP TABLE facture_product');
        }

        if ($this->tableExists('facture')) {
            $this->addSql('DROP TABLE facture');
        }

        if ($this->tableExists('products')) {
            $this->addSql('DROP TABLE products');
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
