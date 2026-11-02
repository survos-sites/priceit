<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102020000 extends AbstractMigration
{
    public function getDescription(): string { return 'InventoryIt collections, AI drafts and explicit reviewed saves'; }

    public function up(Schema $schema): void
    {
        $collection = $schema->createTable('inventory_collection');
        $collection->addColumn('id', 'integer', ['autoincrement' => true]);
        $collection->addColumn('name', 'string', ['length' => 120]);
        $collection->setPrimaryKey(['id']);
        $collection->addUniqueIndex(['name']);
        $item = $schema->getTable('item');
        $item->addColumn('inventory_collection_id', 'integer', ['notnull' => false]);
        $item->addColumn('inventory_saved_at', 'datetime_immutable', ['notnull' => false]);
        $item->addColumn('storage_location', 'string', ['length' => 255, 'notnull' => false]);
        $item->addColumn('inventory_suggestion', 'json', ['notnull' => false]);
        $item->addIndex(['inventory_collection_id']);
        $item->addForeignKeyConstraint('inventory_collection', ['inventory_collection_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve reviewed inventory and collection membership.');
    }
}
