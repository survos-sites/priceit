<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the DC2Type comment from item.ebay_listed_at.
 *
 * Auto-generated, and the generated down() has been REMOVED by hand. It proposed
 * `CREATE SCHEMA _timescaledb_catalog` and seven siblings, because this migration
 * was diffed before config/packages/doctrine.yaml carried the schema_filter that
 * hides the TimescaleDB extension's own catalog from Doctrine. Running that down()
 * against any database would have tried to recreate Timescale's internals.
 *
 * The filter is in place now, so a re-diff would produce nothing. This is kept
 * rather than deleted only because it is already recorded as executed; removing
 * the file would leave Doctrine reporting an executed migration it cannot find.
 */
final class Version20260908163223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the DC2Type comment from item.ebay_listed_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN item.ebay_listed_at IS ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN item.ebay_listed_at IS '(DC2Type:datetime_immutable)'");
    }
}
