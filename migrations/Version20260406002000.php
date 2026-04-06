<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair booking identity columns and primary keys for Doctrine auto-generated IDs.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        $this->repairIdentity('places');
        $this->repairIdentity('place_images');
        $this->repairIdentity('booking');
        $this->repairIdentity('review');
    }

    public function down(Schema $schema): void
    {
        // Intentionally left blank: reverting identity repairs is unsafe for production data.
    }

    private function repairIdentity(string $table): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'id')) {
            return;
        }

        $hasIdDuplicates = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM (SELECT id FROM %s GROUP BY id HAVING COUNT(*) > 1) duplicate_ids',
                $this->q($table)
            )
        ) > 0;
        $this->abortIf($hasIdDuplicates, sprintf('Cannot repair %s.id because duplicate IDs exist.', $table));

        $hasNullIds = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE id IS NULL', $this->q($table))
        ) > 0;
        $this->abortIf($hasNullIds, sprintf('Cannot repair %s.id because NULL IDs exist.', $table));

        $hasPrimary = (bool) $this->connection->fetchOne(
            'SELECT 1
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
               AND CONSTRAINT_NAME = :constraint
             LIMIT 1',
            [
                'table' => $table,
                'column' => 'id',
                'constraint' => 'PRIMARY',
            ]
        );

        if (!$hasPrimary) {
            $hasAnyPrimary = (bool) $this->connection->fetchOne(
                'SELECT 1
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND CONSTRAINT_TYPE = :type
                 LIMIT 1',
                [
                    'table' => $table,
                    'type' => 'PRIMARY KEY',
                ]
            );

            $this->abortIf($hasAnyPrimary, sprintf('Cannot add PRIMARY KEY(id) to %s because another primary key exists.', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD PRIMARY KEY (id)', $this->q($table)));
        }

        $isAutoIncrement = (bool) $this->connection->fetchOne(
            'SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
               AND EXTRA LIKE :extra
             LIMIT 1',
            [
                'table' => $table,
                'column' => 'id',
                'extra' => '%auto_increment%',
            ]
        );

        if (!$isAutoIncrement) {
            $this->addSql(sprintf('ALTER TABLE %s MODIFY id INT NOT NULL AUTO_INCREMENT', $this->q($table)));
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

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
