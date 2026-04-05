<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260406000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert user.id from integer to UUID string and migrate user_id/userId references.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform),
            'This migration supports only MySQL/MariaDB.'
        );

        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['user'])) {
            return;
        }

        $userColumns = $schemaManager->listTableColumns('user');
        if (!isset($userColumns['id'])) {
            return;
        }

        $idColumn = $userColumns['id'];
        $isAlreadyUuid = $idColumn->getType() instanceof StringType && $idColumn->getLength() === 36;
        if ($isAlreadyUuid) {
            return;
        }

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            if (!isset($userColumns['new_id'])) {
                $this->connection->executeStatement('ALTER TABLE `user` ADD `new_id` CHAR(36) DEFAULT NULL');
            }

            /** @var array<int, array{id: string}> $users */
            $users = $this->connection->fetchAllAssociative('SELECT `id` FROM `user`');
            $idMap = [];

            foreach ($users as $row) {
                $oldId = (string) $row['id'];
                $newId = $this->generateUuidV4();
                $idMap[$oldId] = $newId;

                $this->connection->executeStatement(
                    'UPDATE `user` SET `new_id` = :newId WHERE `id` = :oldId',
                    [
                        'newId' => $newId,
                        'oldId' => $oldId,
                    ]
                );
            }

            $schemaManager = $this->connection->createSchemaManager();
            foreach ($schemaManager->listTableNames() as $tableName) {
                if (strtolower($tableName) === 'user') {
                    continue;
                }

                $tableColumns = $schemaManager->listTableColumns($tableName);
                foreach ($tableColumns as $columnName => $column) {
                    $normalized = strtolower($columnName);
                    if (!in_array($normalized, ['user_id', 'userid'], true)) {
                        continue;
                    }

                    $notNullSql = $column->getNotnull() ? 'NOT NULL' : 'DEFAULT NULL';
                    $this->connection->executeStatement(sprintf(
                        'ALTER TABLE %s MODIFY %s CHAR(36) %s',
                        $this->q($tableName),
                        $this->q($columnName),
                        $notNullSql
                    ));

                    foreach ($idMap as $oldId => $newId) {
                        $this->connection->executeStatement(
                            sprintf('UPDATE %s SET %s = :newId WHERE %s = :oldId', $this->q($tableName), $this->q($columnName), $this->q($columnName)),
                            [
                                'newId' => $newId,
                                'oldId' => $oldId,
                            ]
                        );
                    }
                }
            }

            $this->connection->executeStatement('ALTER TABLE `user` MODIFY `id` INT NOT NULL');
            $this->connection->executeStatement('ALTER TABLE `user` DROP PRIMARY KEY');
            $this->connection->executeStatement('ALTER TABLE `user` DROP COLUMN `id`');
            $this->connection->executeStatement('ALTER TABLE `user` CHANGE `new_id` `id` CHAR(36) NOT NULL');
            $this->connection->executeStatement('ALTER TABLE `user` ADD PRIMARY KEY (`id`)');
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Cannot safely convert UUID user IDs back to integer IDs.');
    }

    private function q(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
