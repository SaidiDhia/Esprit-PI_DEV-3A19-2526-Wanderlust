<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260409175542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activites CHANGE categorie categorie VARCHAR(50) NOT NULL, CHANGE status status VARCHAR(50) DEFAULT \'en_attente\' NOT NULL');
        $this->addSql('ALTER TABLE events CHANGE image image VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activites CHANGE categorie categorie ENUM(\'desert\', \'mer\', \'aerien\', \'nature\', \'culture\') NOT NULL, CHANGE status status ENUM(\'en_attente\', \'accepte\', \'refuse\') DEFAULT \'en_attente\' NOT NULL');
        $this->addSql('ALTER TABLE events CHANGE image image VARCHAR(255) NOT NULL');
    }
}
