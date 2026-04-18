<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bot_behavior_score and marketplace_fraud_score to risk_assessment';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('risk_assessment');

        if (!isset($columns['bot_behavior_score'])) {
            $this->addSql('ALTER TABLE risk_assessment ADD bot_behavior_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER message_toxicity_score');
        }

        if (!isset($columns['marketplace_fraud_score'])) {
            $this->addSql('ALTER TABLE risk_assessment ADD marketplace_fraud_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER cancellation_abuse_score');
        }
    }

    public function down(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('risk_assessment');

        if (isset($columns['marketplace_fraud_score'])) {
            $this->addSql('ALTER TABLE risk_assessment DROP COLUMN marketplace_fraud_score');
        }

        if (isset($columns['bot_behavior_score'])) {
            $this->addSql('ALTER TABLE risk_assessment DROP COLUMN bot_behavior_score');
        }
    }
}