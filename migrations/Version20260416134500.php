<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416134500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create event_images table and FK to events when missing.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if (!$this->tableExists('event_images')) {
            $this->addSql("CREATE TABLE event_images (id INT AUTO_INCREMENT NOT NULL, image_path VARCHAR(255) NOT NULL, original_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, event_id INT NOT NULL, INDEX IDX_D286C93871F7E88B (event_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$this->foreignKeyExists('event_images', 'FK_D286C93871F7E88B')) {
            $this->addSql('ALTER TABLE event_images ADD CONSTRAINT FK_D286C93871F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('event_images')) {
            $this->addSql('DROP TABLE event_images');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            ['table' => $table]
        );
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint AND CONSTRAINT_TYPE = "FOREIGN KEY" LIMIT 1',
            [
                'table' => $table,
                'constraint' => $constraint,
            ]
        );
    }
}
