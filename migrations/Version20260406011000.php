<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align marketplace UUID columns with users.id collation to fix JOIN compatibility.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Keep users.id unchanged (it is referenced by many foreign keys).
        // Instead, align marketplace UUID columns to utf8mb4_general_ci.
        if ($this->tableExists('products') && $this->columnExists('products', 'userId')) {
            $this->addSql('ALTER TABLE products MODIFY COLUMN userId CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }

        if ($this->tableExists('facture') && $this->columnExists('facture', 'user_id')) {
            $this->addSql('ALTER TABLE facture MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }

        if ($this->tableExists('cart') && $this->columnExists('cart', 'user_id')) {
            $this->addSql('ALTER TABLE cart MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('products') && $this->columnExists('products', 'userId')) {
            $this->addSql('ALTER TABLE products MODIFY COLUMN userId CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        if ($this->tableExists('facture') && $this->columnExists('facture', 'user_id')) {
            $this->addSql('ALTER TABLE facture MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        if ($this->tableExists('cart') && $this->columnExists('cart', 'user_id')) {
            $this->addSql('ALTER TABLE cart MODIFY COLUMN user_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
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
