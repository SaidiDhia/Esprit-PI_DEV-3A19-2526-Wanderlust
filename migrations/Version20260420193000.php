<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing reservations columns nombre_adultes and nombre_enfants with backfill.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if (!$this->tableExists('reservations')) {
            return;
        }

        if (!$this->columnExists('reservations', 'nombre_adultes')) {
            $this->addSql('ALTER TABLE reservations ADD nombre_adultes INT NOT NULL DEFAULT 0');
        }

        if (!$this->columnExists('reservations', 'nombre_enfants')) {
            $this->addSql('ALTER TABLE reservations ADD nombre_enfants INT NOT NULL DEFAULT 0');
        }

        // Keep existing records coherent with historical nombre_personnes data.
        $this->addSql('UPDATE reservations SET nombre_adultes = CASE WHEN nombre_personnes > 0 THEN nombre_personnes ELSE 1 END WHERE nombre_adultes = 0 AND nombre_enfants = 0');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if (!$this->tableExists('reservations')) {
            return;
        }

        if ($this->columnExists('reservations', 'nombre_adultes')) {
            $this->addSql('ALTER TABLE reservations DROP COLUMN nombre_adultes');
        }

        if ($this->columnExists('reservations', 'nombre_enfants')) {
            $this->addSql('ALTER TABLE reservations DROP COLUMN nombre_enfants');
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
