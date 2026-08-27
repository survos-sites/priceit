<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260827144652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item ADD profile VARCHAR(32) DEFAULT \'auction\' NOT NULL');
        $this->addSql('ALTER TABLE item ADD asset_number VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F1B251E7EBD9049 ON item (asset_number)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1F1B251E7EBD9049');
        $this->addSql('ALTER TABLE item DROP profile');
        $this->addSql('ALTER TABLE item DROP asset_number');
    }
}
