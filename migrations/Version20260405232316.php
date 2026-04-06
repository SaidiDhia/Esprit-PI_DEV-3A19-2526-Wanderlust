<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405232316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservations (id INT AUTO_INCREMENT NOT NULL, nom_complet VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, telephone INT NOT NULL, nombre_personnes INT NOT NULL, prix_total NUMERIC(10, 2) NOT NULL, date_reservation DATETIME NOT NULL, demandes_speciales LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, methode_paiement VARCHAR(50) DEFAULT NULL, montant_paye NUMERIC(10, 2) DEFAULT NULL, date_creation DATETIME NOT NULL, date_modification DATETIME DEFAULT NULL, id_event INT NOT NULL, INDEX IDX_4DA239D52B4B97 (id_event), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239D52B4B97 FOREIGN KEY (id_event) REFERENCES events (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239D52B4B97');
        $this->addSql('DROP TABLE reservations');
    }
}
