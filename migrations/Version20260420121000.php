<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure blog_notifications.id is primary key with AUTO_INCREMENT';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['blog_notifications'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('blog_notifications');
        if (!isset($columns['id'])) {
            return;
        }

        $table = $schemaManager->listTableDetails('blog_notifications');
        $primaryKey = $table->getPrimaryKey();

        if ($primaryKey === null) {
            $this->addSql('ALTER TABLE blog_notifications ADD PRIMARY KEY (id)');
        }

        if (!$columns['id']->getAutoincrement()) {
            $this->addSql('ALTER TABLE blog_notifications MODIFY id INT NOT NULL AUTO_INCREMENT');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['blog_notifications'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('blog_notifications');
        if (isset($columns['id']) && $columns['id']->getAutoincrement()) {
            $this->addSql('ALTER TABLE blog_notifications MODIFY id INT NOT NULL');
        }

        $table = $schemaManager->listTableDetails('blog_notifications');
        $primaryKey = $table->getPrimaryKey();
        if ($primaryKey !== null && $primaryKey->getColumns() === ['id']) {
            $this->addSql('ALTER TABLE blog_notifications DROP PRIMARY KEY');
        }
    }
}
