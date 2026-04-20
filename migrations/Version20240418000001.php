<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240418000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status column to events table';
    }

    public function up(Schema $schema): void
    {
        // Drop old statut column if it exists
        if ($schema->getTable('events')->hasColumn('statut')) {
            $schema->getTable('events')->dropColumn('statut');
        }
        
        // Add new status column with enum
        $table = $schema->getTable('events');
        $table->addColumn('status', 'string', [
            'length' => 50,
            'default' => 'en_attente',
            'notNull' => false,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Remove status column
        $schema->getTable('events')->dropColumn('status');
        
        // Add back old statut column if needed
        $table = $schema->getTable('events');
        $table->addColumn('statut', 'string', [
            'length' => 20,
            'notNull' => false,
        ]);
    }
}
