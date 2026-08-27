<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store AI-generated equipment type and category for Lions Quickbase export';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD category VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD equipment_type VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP category');
        $this->addSql('ALTER TABLE item DROP equipment_type');
    }
}
