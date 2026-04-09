<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406009000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_title and product_image columns to facture_product table for order history.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('facture_product')) {
            if (!$this->columnExists('facture_product', 'product_title')) {
                $this->addSql('ALTER TABLE facture_product ADD COLUMN product_title VARCHAR(255) DEFAULT NULL AFTER price');
            }

            if (!$this->columnExists('facture_product', 'product_image')) {
                $this->addSql('ALTER TABLE facture_product ADD COLUMN product_image VARCHAR(255) DEFAULT NULL AFTER product_title');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('facture_product')) {
            if ($this->columnExists('facture_product', 'product_image')) {
                $this->addSql('ALTER TABLE facture_product DROP COLUMN product_image');
            }

            if ($this->columnExists('facture_product', 'product_title')) {
                $this->addSql('ALTER TABLE facture_product DROP COLUMN product_title');
            }
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
