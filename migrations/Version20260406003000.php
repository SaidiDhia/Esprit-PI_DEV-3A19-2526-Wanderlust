<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align review.user_id collation with users.id to avoid illegal mix of collations on joins.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if (!$this->tableExists('review') || !$this->columnExists('review', 'user_id')) {
            return;
        }

        $collation = (string) $this->connection->fetchOne(
            'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            [
                'table' => 'review',
                'column' => 'user_id',
            ]
        );

        if ($collation !== 'utf8mb4_general_ci') {
            $this->addSql("ALTER TABLE `review` MODIFY `user_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('review') && $this->columnExists('review', 'user_id')) {
            $this->addSql("ALTER TABLE `review` MODIFY `user_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
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
