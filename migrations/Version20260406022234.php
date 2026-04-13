<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260406022234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update reservations table to fix nombrePersonnes null values';
    }

    public function up(Schema $schema): void
    {
        // Mettre à jour les valeurs null de nombrePersonnes à 1
        $this->addSql('UPDATE reservations SET nombre_personnes = 1 WHERE nombre_personnes IS NULL');
        
        // Rendre la colonne NOT NULL
        $this->addSql('ALTER TABLE reservations CHANGE nombre_personnes nombre_personnes INT NOT NULL');
        
        // Mettre à jour la colonne téléphone
        $this->addSql('ALTER TABLE reservations CHANGE telephone telephone VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revenir à la version précédente
        $this->addSql('ALTER TABLE reservations CHANGE nombre_personnes nombre_personnes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservations CHANGE telephone telephone VARCHAR(255) DEFAULT NULL');
    }
}
