<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix collation mismatch in userId columns across tables to allow proper JOINs with users.id.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Fix userId column collation in products table
        if ($this->tableExists('products') && $this->columnExists('products', 'userId')) {
            $this->addSql('ALTER TABLE products MODIFY COLUMN userId CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        // Fix user_id column collation in facture table
        if ($this->tableExists('facture') && $this->columnExists('facture', 'user_id')) {
            $this->addSql('ALTER TABLE facture MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        // Fix cart user_id column collation
        if ($this->tableExists('cart') && $this->columnExists('cart', 'user_id')) {
            $this->addSql('ALTER TABLE cart MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Revert products.userId
        if ($this->tableExists('products') && $this->columnExists('products', 'userId')) {
            $this->addSql('ALTER TABLE products MODIFY COLUMN userId CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }

        // Revert facture.user_id
        if ($this->tableExists('facture') && $this->columnExists('facture', 'user_id')) {
            $this->addSql('ALTER TABLE facture MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }

        // Revert cart.user_id
        if ($this->tableExists('cart') && $this->columnExists('cart', 'user_id')) {
            $this->addSql('ALTER TABLE cart MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            ['table' => $table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
            [
                'table' => $table,
                'column' => $column,
            ]
        );
    }
}
