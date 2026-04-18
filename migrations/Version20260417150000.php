<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create activity_log table for cross-module user action tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activity_log (
            id BIGINT AUTO_INCREMENT NOT NULL,
            module VARCHAR(50) NOT NULL,
            action VARCHAR(120) NOT NULL,
            user_id VARCHAR(36) DEFAULT NULL,
            user_name VARCHAR(255) DEFAULT NULL,
            user_avatar VARCHAR(255) DEFAULT NULL,
            target_type VARCHAR(80) DEFAULT NULL,
            target_id VARCHAR(120) DEFAULT NULL,
            target_name VARCHAR(255) DEFAULT NULL,
            target_image VARCHAR(255) DEFAULT NULL,
            content LONGTEXT DEFAULT NULL,
            destination VARCHAR(255) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_activity_log_created_at (created_at),
            INDEX idx_activity_log_module (module),
            INDEX idx_activity_log_user_id (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE admin_recent_risk_alert_ack (
            admin_user_id VARCHAR(36) NOT NULL,
            activity_log_id BIGINT NOT NULL,
            acknowledged_at DATETIME NOT NULL,
            INDEX idx_admin_recent_risk_alert_ack_admin_user_id (admin_user_id),
            INDEX idx_admin_recent_risk_alert_ack_activity_log_id (activity_log_id),
            PRIMARY KEY(admin_user_id, activity_log_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_recent_risk_alert_ack');
        $this->addSql('DROP TABLE activity_log');
    }
}
