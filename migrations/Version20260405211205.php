<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405211205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY `FK_885DBAFA6B3CA4B`');
        $this->addSql('DROP INDEX fk_885dbafa6b3ca4b ON posts');
        $this->addSql('CREATE INDEX IDX_885DBAFA6B3CA4B ON posts (id_user)');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT `FK_885DBAFA6B3CA4B` FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('ALTER TABLE posts_sauvegardes ADD CONSTRAINT FK_96E7F2C6B3CA4B FOREIGN KEY (id_user) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_96E7F2C6B3CA4B ON posts_sauvegardes (id_user)');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB36B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('DROP INDEX fk_reactions_user ON reactions');
        $this->addSql('CREATE INDEX IDX_38737FB36B3CA4B ON reactions (id_user)');
        $this->addSql('ALTER TABLE users CHANGE created_at created_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX email ON users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFA6B3CA4B');
        $this->addSql('DROP INDEX idx_885dbafa6b3ca4b ON posts');
        $this->addSql('CREATE INDEX FK_885DBAFA6B3CA4B ON posts (id_user)');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFA6B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('ALTER TABLE posts_sauvegardes DROP FOREIGN KEY FK_96E7F2C6B3CA4B');
        $this->addSql('DROP INDEX IDX_96E7F2C6B3CA4B ON posts_sauvegardes');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB36B3CA4B');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB36B3CA4B');
        $this->addSql('DROP INDEX idx_38737fb36b3ca4b ON reactions');
        $this->addSql('CREATE INDEX fk_reactions_user ON reactions (id_user)');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB36B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('ALTER TABLE users CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('DROP INDEX uniq_1483a5e9e7927c74 ON users');
        $this->addSql('CREATE UNIQUE INDEX email ON users (email)');
    }
}
