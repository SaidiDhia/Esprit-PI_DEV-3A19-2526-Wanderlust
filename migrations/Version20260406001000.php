<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Upgrade the existing booking schema with the missing stay fields.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('places')) {
            $this->addColumnIfMissing('places', 'image_url', "VARCHAR(255) DEFAULT NULL AFTER `status`");
            $this->addColumnIfMissing('places', 'denial_reason', "VARCHAR(255) DEFAULT NULL AFTER `image_url`");
            $this->addColumnIfMissing('places', 'avg_rating', "NUMERIC(3, 2) DEFAULT NULL AFTER `longitude`");
            $this->addColumnIfMissing('places', 'reviews_count', "INT NOT NULL DEFAULT 0 AFTER `avg_rating`");
        } else {
            $this->addSql(<<<'SQL'
CREATE TABLE places (
    id INT AUTO_INCREMENT NOT NULL,
    host_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    price_per_day NUMERIC(10, 2) NOT NULL,
    capacity INT NOT NULL,
    max_guests INT NOT NULL DEFAULT 1,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) NOT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    denial_reason VARCHAR(255) DEFAULT NULL,
    latitude NUMERIC(10, 8) DEFAULT NULL,
    longitude NUMERIC(11, 8) DEFAULT NULL,
    avg_rating NUMERIC(3, 2) DEFAULT NULL,
    reviews_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_PLACES_HOST_ID (host_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        }

        if ($this->tableExists('booking')) {
            $this->addColumnIfMissing('booking', 'cancel_reason', "VARCHAR(255) DEFAULT NULL");
            $this->addColumnIfMissing('booking', 'cancelled_by', "VARCHAR(10) DEFAULT NULL");
            $this->addColumnIfMissing('booking', 'refund_amount', "NUMERIC(10, 2) NOT NULL DEFAULT 0");
            $this->addColumnIfMissing('booking', 'cancelled_at', "DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            $this->addColumnIfMissing('booking', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)'");
        } else {
            $this->addSql(<<<'SQL'
CREATE TABLE booking (
    id INT AUTO_INCREMENT NOT NULL,
    place_id INT NOT NULL,
    user_id CHAR(36) NOT NULL,
    start_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
    end_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
    total_price NUMERIC(10, 2) NOT NULL,
    guests_count INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    refund_amount NUMERIC(10, 2) NOT NULL DEFAULT 0,
    cancelled_by VARCHAR(10) DEFAULT NULL,
    cancel_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_BOOKING_PLACE_ID (place_id),
    INDEX IDX_BOOKING_USER_ID (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        }

        if (!$this->tableExists('place_images')) {
            $this->addSql(<<<'SQL'
CREATE TABLE place_images (
    id INT AUTO_INCREMENT NOT NULL,
    place_id INT NOT NULL,
    url VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_PLACE_IMAGES_PLACE_ID (place_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        }

        if (!$this->tableExists('review')) {
            $this->addSql(<<<'SQL'
CREATE TABLE review (
    id INT AUTO_INCREMENT NOT NULL,
    place_id INT NOT NULL,
    user_id CHAR(36) NOT NULL,
    rating INT NOT NULL,
    comment LONGTEXT DEFAULT NULL,
    sentiment VARCHAR(20) DEFAULT NULL,
    ai_summary VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_REVIEW_PLACE_ID (place_id),
    INDEX IDX_REVIEW_USER_ID (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        }

    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        if ($this->tableExists('review')) {
            $this->addSql('DROP TABLE review');
        }

        if ($this->tableExists('place_images')) {
            $this->addSql('DROP TABLE place_images');
        }

        if ($this->tableExists('booking')) {
            $this->addSql('DROP TABLE booking');
        }

        if ($this->tableExists('places')) {
            $this->addSql('DROP TABLE places');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            ['table' => $table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
            [
                'table' => $table,
                'column' => $column,
            ]
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $this->q($table), $this->q($column), $definition));
        }
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}