<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260419164307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activites ADD CONSTRAINT FK_766B5EB5B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activites RENAME INDEX idx_activites_created_by TO IDX_766B5EB5B03A8386');
        $this->addSql('ALTER TABLE booking CHANGE refund_amount refund_amount NUMERIC(10, 2) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEDA6A219 FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E00CEDDEDA6A219 ON booking (place_id)');
        $this->addSql('CREATE INDEX IDX_E00CEDDEA76ED395 ON booking (user_id)');
        $this->addSql('ALTER TABLE cart CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE user_id user_id VARCHAR(36) NOT NULL, CHANGE total_price total_price NUMERIC(10, 2) NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE cart_item CHANGE id id INT AUTO_INCREMENT NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id)');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25274584665A FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('CREATE INDEX IDX_F0FE25271AD5CDBF ON cart_item (cart_id)');
        $this->addSql('CREATE INDEX IDX_F0FE25274584665A ON cart_item (product_id)');
        $this->addSql('ALTER TABLE commentaires CHANGE id_commentaire id_commentaire INT AUTO_INCREMENT NOT NULL, ADD PRIMARY KEY (id_commentaire)');
        $this->addSql('ALTER TABLE commentaires ADD CONSTRAINT FK_D9BEC0C4D1AA708F FOREIGN KEY (id_post) REFERENCES posts (id_post) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commentaires ADD CONSTRAINT FK_D9BEC0C46B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('ALTER TABLE commentaires ADD CONSTRAINT FK_D9BEC0C41BB9D5A2 FOREIGN KEY (id_parent) REFERENCES commentaires (id_commentaire) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_D9BEC0C4D1AA708F ON commentaires (id_post)');
        $this->addSql('CREATE INDEX IDX_D9BEC0C46B3CA4B ON commentaires (id_user)');
        $this->addSql('CREATE INDEX IDX_D9BEC0C41BB9D5A2 ON commentaires (id_parent)');
        $this->addSql('ALTER TABLE conversation DROP created_by, CHANGE id id BIGINT AUTO_INCREMENT NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE conversation_user DROP joined_at, DROP added_by, DROP last_read_message_id, DROP is_muted, DROP muted_until, DROP is_favorite, DROP nickname, CHANGE id id BIGINT AUTO_INCREMENT NOT NULL, CHANGE user_id user_id VARCHAR(36) NOT NULL, CHANGE role role VARCHAR(20) DEFAULT \'MEMBER\' NOT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE delivery_address CHANGE id id INT AUTO_INCREMENT NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE delivery_address ADD CONSTRAINT FK_750D05F7F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id_facture)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_750D05F7F2DEE08 ON delivery_address (facture_id)');
        $this->addSql('ALTER TABLE events ADD share_count INT DEFAULT 0 NOT NULL, CHANGE status status VARCHAR(50) DEFAULT \'en_attente\' NOT NULL');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574AB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE events RENAME INDEX idx_events_created_by TO IDX_5387574AB03A8386');
        $this->addSql('ALTER TABLE facture CHANGE id_facture id_facture INT AUTO_INCREMENT NOT NULL, CHANGE user_id user_id VARCHAR(36) NOT NULL, ADD PRIMARY KEY (id_facture)');
        $this->addSql('ALTER TABLE facture_product CHANGE id id INT AUTO_INCREMENT NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE facture_product ADD CONSTRAINT FK_9BADA5F47F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id_facture)');
        $this->addSql('ALTER TABLE facture_product ADD CONSTRAINT FK_9BADA5F44584665A FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('CREATE INDEX IDX_9BADA5F47F2DEE08 ON facture_product (facture_id)');
        $this->addSql('CREATE INDEX IDX_9BADA5F44584665A ON facture_product (product_id)');
        $this->addSql('ALTER TABLE message DROP thumbnail_url, DROP mime_type, DROP duration, DROP status, DROP reactions, CHANGE id id BIGINT AUTO_INCREMENT NOT NULL, CHANGE content content LONGTEXT DEFAULT NULL, CHANGE message_type message_type VARCHAR(20) DEFAULT \'TEXT\' NOT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE place_images CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE place_images ADD CONSTRAINT FK_42D1B903DA6A219 FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_42D1B903DA6A219 ON place_images (place_id)');
        $this->addSql('ALTER TABLE places CHANGE host_id host_id CHAR(36) NOT NULL, CHANGE description description LONGTEXT DEFAULT NULL, CHANGE status status VARCHAR(20) DEFAULT \'PENDING\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE places ADD CONSTRAINT FK_FEAF6C551FB8D185 FOREIGN KEY (host_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_FEAF6C551FB8D185 ON places (host_id)');
        $this->addSql('ALTER TABLE posts CHANGE id_post id_post INT AUTO_INCREMENT NOT NULL, CHANGE contenu contenu LONGTEXT NOT NULL, CHANGE id_user id_user CHAR(36) NOT NULL, ADD PRIMARY KEY (id_post)');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFA6B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('CREATE INDEX IDX_885DBAFA6B3CA4B ON posts (id_user)');
        $this->addSql('ALTER TABLE posts_sauvegardes CHANGE id_user id_user CHAR(36) NOT NULL, ADD PRIMARY KEY (id_user, id_post)');
        $this->addSql('ALTER TABLE posts_sauvegardes ADD CONSTRAINT FK_96E7F2C6B3CA4B FOREIGN KEY (id_user) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE posts_sauvegardes ADD CONSTRAINT FK_96E7F2CD1AA708F FOREIGN KEY (id_post) REFERENCES posts (id_post) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_96E7F2C6B3CA4B ON posts_sauvegardes (id_user)');
        $this->addSql('CREATE INDEX IDX_96E7F2CD1AA708F ON posts_sauvegardes (id_post)');
        $this->addSql('CREATE UNIQUE INDEX idx_unique_save ON posts_sauvegardes (id_user, id_post)');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB3D1AA708F FOREIGN KEY (id_post) REFERENCES posts (id_post) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB37FE2A54B FOREIGN KEY (id_commentaire) REFERENCES commentaires (id_commentaire) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT FK_38737FB36B3CA4B FOREIGN KEY (id_user) REFERENCES users (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reservations RENAME INDEX idx_reservations_user TO IDX_4DA239A76ED395');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6DA6A219 FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE users CHANGE email email VARCHAR(255) NOT NULL, CHANGE role role VARCHAR(255) DEFAULT \'PARTICIPANT\' NOT NULL, CHANGE full_name full_name VARCHAR(255) DEFAULT NULL, CHANGE phone_number phone_number VARCHAR(30) DEFAULT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE tfa_method tfa_method VARCHAR(255) DEFAULT \'NONE\' NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON users (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activites DROP FOREIGN KEY FK_766B5EB5B03A8386');
        $this->addSql('ALTER TABLE activites RENAME INDEX idx_766b5eb5b03a8386 TO IDX_ACTIVITES_CREATED_BY');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEDA6A219');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA76ED395');
        $this->addSql('DROP INDEX IDX_E00CEDDEDA6A219 ON booking');
        $this->addSql('DROP INDEX IDX_E00CEDDEA76ED395 ON booking');
        $this->addSql('ALTER TABLE booking CHANGE refund_amount refund_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE cart MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE cart CHANGE id id INT NOT NULL, CHANGE user_id user_id CHAR(36) NOT NULL COLLATE `utf8mb4_general_ci`, CHANGE total_price total_price NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25271AD5CDBF');
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25274584665A');
        $this->addSql('DROP INDEX IDX_F0FE25271AD5CDBF ON cart_item');
        $this->addSql('DROP INDEX IDX_F0FE25274584665A ON cart_item');
        $this->addSql('ALTER TABLE cart_item MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE cart_item CHANGE id id INT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE commentaires DROP FOREIGN KEY FK_D9BEC0C4D1AA708F');
        $this->addSql('ALTER TABLE commentaires DROP FOREIGN KEY FK_D9BEC0C46B3CA4B');
        $this->addSql('ALTER TABLE commentaires DROP FOREIGN KEY FK_D9BEC0C41BB9D5A2');
        $this->addSql('DROP INDEX IDX_D9BEC0C4D1AA708F ON commentaires');
        $this->addSql('DROP INDEX IDX_D9BEC0C46B3CA4B ON commentaires');
        $this->addSql('DROP INDEX IDX_D9BEC0C41BB9D5A2 ON commentaires');
        $this->addSql('ALTER TABLE commentaires MODIFY id_commentaire INT NOT NULL');
        $this->addSql('ALTER TABLE commentaires CHANGE id_commentaire id_commentaire INT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE conversation MODIFY id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE conversation ADD created_by CHAR(36) DEFAULT NULL, CHANGE id id BIGINT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE conversation_user MODIFY id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE conversation_user ADD joined_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD added_by VARCHAR(36) DEFAULT NULL, ADD last_read_message_id BIGINT DEFAULT NULL, ADD is_muted TINYINT DEFAULT 0, ADD muted_until DATETIME DEFAULT NULL, ADD is_favorite TINYINT DEFAULT 0, ADD nickname VARCHAR(100) DEFAULT NULL, CHANGE id id BIGINT NOT NULL, CHANGE user_id user_id CHAR(36) NOT NULL, CHANGE role role ENUM(\'CREATOR\', \'ADMIN\', \'MEMBER\') DEFAULT \'MEMBER\', CHANGE is_active is_active TINYINT DEFAULT 1, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE delivery_address DROP FOREIGN KEY FK_750D05F7F2DEE08');
        $this->addSql('DROP INDEX UNIQ_750D05F7F2DEE08 ON delivery_address');
        $this->addSql('ALTER TABLE delivery_address MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE delivery_address CHANGE id id INT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY FK_5387574AB03A8386');
        $this->addSql('ALTER TABLE events DROP share_count, CHANGE status status VARCHAR(50) DEFAULT \'en_attente\'');
        $this->addSql('ALTER TABLE events RENAME INDEX idx_5387574ab03a8386 TO IDX_EVENTS_CREATED_BY');
        $this->addSql('ALTER TABLE facture MODIFY id_facture INT NOT NULL');
        $this->addSql('ALTER TABLE facture CHANGE id_facture id_facture INT NOT NULL, CHANGE user_id user_id CHAR(36) NOT NULL COLLATE `utf8mb4_general_ci`, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE facture_product DROP FOREIGN KEY FK_9BADA5F47F2DEE08');
        $this->addSql('ALTER TABLE facture_product DROP FOREIGN KEY FK_9BADA5F44584665A');
        $this->addSql('DROP INDEX IDX_9BADA5F47F2DEE08 ON facture_product');
        $this->addSql('DROP INDEX IDX_9BADA5F44584665A ON facture_product');
        $this->addSql('ALTER TABLE facture_product MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE facture_product CHANGE id id INT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE message MODIFY id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE message ADD thumbnail_url VARCHAR(500) DEFAULT NULL, ADD mime_type VARCHAR(100) DEFAULT NULL, ADD duration INT DEFAULT NULL, ADD status ENUM(\'SENT\', \'DELIVERED\', \'READ\', \'FAILED\') DEFAULT \'SENT\', ADD reactions LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE id id BIGINT NOT NULL, CHANGE content content TEXT NOT NULL, CHANGE message_type message_type ENUM(\'TEXT\', \'IMAGE\', \'VIDEO\', \'FILE\', \'LOCATION\', \'AUDIO\') DEFAULT \'TEXT\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE places DROP FOREIGN KEY FK_FEAF6C551FB8D185');
        $this->addSql('DROP INDEX IDX_FEAF6C551FB8D185 ON places');
        $this->addSql('ALTER TABLE places CHANGE description description TEXT DEFAULT NULL, CHANGE status status ENUM(\'PENDING\', \'APPROVED\', \'DENIED\') DEFAULT \'PENDING\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE host_id host_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE place_images DROP FOREIGN KEY FK_42D1B903DA6A219');
        $this->addSql('DROP INDEX IDX_42D1B903DA6A219 ON place_images');
        $this->addSql('ALTER TABLE place_images CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFA6B3CA4B');
        $this->addSql('DROP INDEX IDX_885DBAFA6B3CA4B ON posts');
        $this->addSql('ALTER TABLE posts MODIFY id_post INT NOT NULL');
        $this->addSql('ALTER TABLE posts CHANGE id_post id_post INT NOT NULL, CHANGE contenu contenu TEXT NOT NULL, CHANGE id_user id_user VARCHAR(36) NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE posts_sauvegardes DROP FOREIGN KEY FK_96E7F2C6B3CA4B');
        $this->addSql('ALTER TABLE posts_sauvegardes DROP FOREIGN KEY FK_96E7F2CD1AA708F');
        $this->addSql('DROP INDEX IDX_96E7F2C6B3CA4B ON posts_sauvegardes');
        $this->addSql('DROP INDEX IDX_96E7F2CD1AA708F ON posts_sauvegardes');
        $this->addSql('DROP INDEX idx_unique_save ON posts_sauvegardes');
        $this->addSql('ALTER TABLE posts_sauvegardes CHANGE id_user id_user INT NOT NULL, DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB3D1AA708F');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB37FE2A54B');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY FK_38737FB36B3CA4B');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239A76ED395');
        $this->addSql('ALTER TABLE reservations RENAME INDEX idx_4da239a76ed395 TO IDX_RESERVATIONS_USER');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6DA6A219');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6A76ED395');
        $this->addSql('DROP INDEX uniq_user_email ON users');
        $this->addSql('ALTER TABLE users CHANGE email email VARCHAR(180) NOT NULL, CHANGE full_name full_name VARCHAR(255) NOT NULL, CHANGE phone_number phone_number VARCHAR(20) DEFAULT NULL, CHANGE role role VARCHAR(255) NOT NULL, CHANGE tfa_method tfa_method VARCHAR(255) NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, DROP PRIMARY KEY');
    }
}
