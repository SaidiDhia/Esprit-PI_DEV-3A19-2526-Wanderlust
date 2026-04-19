<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create testimonials table for dedicated homepage testimonials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE testimonials (
            id INT AUTO_INCREMENT NOT NULL,
            user_id VARCHAR(36) DEFAULT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\",
            INDEX idx_testimonials_created_at (created_at),
            INDEX idx_testimonials_user_id (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE testimonials ADD CONSTRAINT FK_TESTIMONIALS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE testimonials');
    }
}
