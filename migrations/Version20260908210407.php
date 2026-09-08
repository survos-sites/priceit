<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908210407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create marketplace_token — OAuth tokens that survive a redeploy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_token (connection_name VARCHAR(64) NOT NULL, access_token TEXT NOT NULL, access_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_token TEXT DEFAULT NULL, refresh_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scopes JSON DEFAULT \'[]\' NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (connection_name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA toolkit_experimental');
        $this->addSql('CREATE SCHEMA timescaledb_information');
        $this->addSql('CREATE SCHEMA timescaledb_experimental');
        $this->addSql('CREATE SCHEMA _timescaledb_internal');
        $this->addSql('CREATE SCHEMA _timescaledb_functions');
        $this->addSql('CREATE SCHEMA _timescaledb_config');
        $this->addSql('CREATE SCHEMA _timescaledb_catalog');
        $this->addSql('CREATE SCHEMA _timescaledb_cache');
        $this->addSql('DROP TABLE marketplace_token');
    }
}
