<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260411190850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_activite (events_id INT NOT NULL, activities_id INT NOT NULL, INDEX IDX_FE99BCC59D6A1065 (events_id), INDEX IDX_FE99BCC52A4DB562 (activities_id), PRIMARY KEY (events_id, activities_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE event_activite ADD CONSTRAINT FK_FE99BCC59D6A1065 FOREIGN KEY (events_id) REFERENCES events (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_activite ADD CONSTRAINT FK_FE99BCC52A4DB562 FOREIGN KEY (activities_id) REFERENCES activites (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE events_activities DROP FOREIGN KEY `FK_60081A642A4DB562`');
        $this->addSql('ALTER TABLE events_activities DROP FOREIGN KEY `FK_60081A649D6A1065`');
        $this->addSql('DROP TABLE events_activities');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE events_activities (events_id INT NOT NULL, activities_id INT NOT NULL, INDEX IDX_60081A642A4DB562 (activities_id), INDEX IDX_60081A649D6A1065 (events_id), PRIMARY KEY (events_id, activities_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE events_activities ADD CONSTRAINT `FK_60081A642A4DB562` FOREIGN KEY (activities_id) REFERENCES activites (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE events_activities ADD CONSTRAINT `FK_60081A649D6A1065` FOREIGN KEY (events_id) REFERENCES events (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_activite DROP FOREIGN KEY FK_FE99BCC59D6A1065');
        $this->addSql('ALTER TABLE event_activite DROP FOREIGN KEY FK_FE99BCC52A4DB562');
        $this->addSql('DROP TABLE event_activite');
    }
}
