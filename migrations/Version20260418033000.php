<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418033000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create risk_assessment table for real-time abuse risk scoring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE risk_assessment (
            id BIGINT AUTO_INCREMENT NOT NULL,
            user_id VARCHAR(36) NOT NULL,
            risk_score DECIMAL(5,2) NOT NULL,
            anomaly_score DECIMAL(5,2) NOT NULL,
            click_speed_score DECIMAL(5,2) NOT NULL,
            login_failure_score DECIMAL(5,2) NOT NULL,
            message_toxicity_score DECIMAL(5,2) NOT NULL,
            cancellation_abuse_score DECIMAL(5,2) NOT NULL,
            risk_band VARCHAR(16) NOT NULL,
            recommended_action VARCHAR(32) NOT NULL,
            details_json LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_risk_user_id (user_id),
            INDEX idx_risk_score (risk_score),
            INDEX idx_risk_updated_at (updated_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE risk_assessment');
    }
}
