<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406008000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment_method to facture and email, notes to delivery_address tables.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        // Add payment_method to facture
        if ($this->tableExists('facture') && !$this->columnExists('facture', 'payment_method')) {
            $this->addSql('ALTER TABLE facture ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL AFTER delivery_status');
        }

        // Add email to delivery_address
        if ($this->tableExists('delivery_address') && !$this->columnExists('delivery_address', 'email')) {
            $this->addSql('ALTER TABLE delivery_address ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER phone');
        }

        // Add notes to delivery_address
        if ($this->tableExists('delivery_address') && !$this->columnExists('delivery_address', 'notes')) {
            $this->addSql('ALTER TABLE delivery_address ADD COLUMN notes LONGTEXT DEFAULT NULL AFTER email');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('delivery_address') && $this->columnExists('delivery_address', 'notes')) {
            $this->addSql('ALTER TABLE delivery_address DROP COLUMN notes');
        }

        if ($this->tableExists('delivery_address') && $this->columnExists('delivery_address', 'email')) {
            $this->addSql('ALTER TABLE delivery_address DROP COLUMN email');
        }

        if ($this->tableExists('facture') && $this->columnExists('facture', 'payment_method')) {
            $this->addSql('ALTER TABLE facture DROP COLUMN payment_method');
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
