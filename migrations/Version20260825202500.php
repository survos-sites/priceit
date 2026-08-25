<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825202500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record whether a captured item should print immediately after pricing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD print_requested BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE item ALTER print_requested DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP print_requested');
    }
}
