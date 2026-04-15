<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add columns for authenticator app secret and face verification reference image';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD tfa_secret VARCHAR(64) DEFAULT NULL, ADD face_reference_image VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP tfa_secret, DROP face_reference_image');
    }
}
