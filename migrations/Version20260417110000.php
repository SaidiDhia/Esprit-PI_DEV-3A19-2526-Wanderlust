<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add language_quality column to review table for AI language quality output.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review ADD language_quality VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review DROP language_quality');
    }
}
