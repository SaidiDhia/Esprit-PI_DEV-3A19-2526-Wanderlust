<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ownership columns for activities, events, and reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activites ADD created_by_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD created_by_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservations ADD user_id CHAR(36) DEFAULT NULL');

        $this->addSql('CREATE INDEX IDX_ACTIVITES_CREATED_BY ON activites (created_by_id)');
        $this->addSql('CREATE INDEX IDX_EVENTS_CREATED_BY ON events (created_by_id)');
        $this->addSql('CREATE INDEX IDX_RESERVATIONS_USER ON reservations (user_id)');

        $this->addSql('ALTER TABLE activites ADD CONSTRAINT FK_ACTIVITES_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_EVENTS_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_RESERVATIONS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activites DROP FOREIGN KEY FK_ACTIVITES_CREATED_BY');
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY FK_EVENTS_CREATED_BY');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_RESERVATIONS_USER');

        $this->addSql('DROP INDEX IDX_ACTIVITES_CREATED_BY ON activites');
        $this->addSql('DROP INDEX IDX_EVENTS_CREATED_BY ON events');
        $this->addSql('DROP INDEX IDX_RESERVATIONS_USER ON reservations');

        $this->addSql('ALTER TABLE activites DROP created_by_id');
        $this->addSql('ALTER TABLE events DROP created_by_id');
        $this->addSql('ALTER TABLE reservations DROP user_id');
    }
}
