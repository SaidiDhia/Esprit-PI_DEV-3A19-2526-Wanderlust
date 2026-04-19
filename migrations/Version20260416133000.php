<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align activites/events schema with current entity mappings (age_minimum, date_limite_inscription, video, date_modification).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activites ADD COLUMN IF NOT EXISTS age_minimum INT DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD COLUMN IF NOT EXISTS date_limite_inscription DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD COLUMN IF NOT EXISTS video VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD COLUMN IF NOT EXISTS date_modification DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activites DROP COLUMN IF EXISTS age_minimum');
        $this->addSql('ALTER TABLE events DROP COLUMN IF EXISTS date_limite_inscription');
        $this->addSql('ALTER TABLE events DROP COLUMN IF EXISTS video');
        $this->addSql('ALTER TABLE events DROP COLUMN IF EXISTS date_modification');
    }
}
