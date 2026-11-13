<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111010000 extends AbstractMigration
{
    public function getDescription(): string { return 'App-owned cemetery catalog for stone collection'; }
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('cemetery');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('name', 'string', ['length' => 200]);
        $table->addColumn('boundary', 'json', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve cemetery records.'); }
}
