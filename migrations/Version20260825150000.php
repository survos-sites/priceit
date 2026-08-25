<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the Lions Inventory record and last successful Quickbase export for each Item.';
    }

    public function up(Schema $schema): void
    {
        $timestampType = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'TIMESTAMP(0) WITHOUT TIME ZONE'
            : 'DATETIME';

        $this->addSql('ALTER TABLE item ADD quickbase_inventory_record_id INTEGER DEFAULT NULL');
        $this->addSql(sprintf('ALTER TABLE item ADD quickbase_exported_at %s DEFAULT NULL', $timestampType));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP COLUMN quickbase_inventory_record_id');
        $this->addSql('ALTER TABLE item DROP COLUMN quickbase_exported_at');
    }
}
