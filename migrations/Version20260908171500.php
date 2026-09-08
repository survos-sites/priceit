<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace the eBay-specific listing columns with a per-provider map.
 *
 * The same object can be listed on more than one marketplace, and the set of
 * marketplaces is configuration rather than schema -- adding Mercado Libre should
 * not have been a migration, and adding the third one now will not be.
 *
 * Safe to drop rather than migrate the old columns: they were added earlier today,
 * only ever existed in dev, and were never populated.
 */
final class Version20260908171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace item.ebay_* columns with item.marketplace_listings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE item ADD marketplace_listings JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE item DROP ebay_offer_id');
        $this->addSql('ALTER TABLE item DROP ebay_listing_id');
        $this->addSql('ALTER TABLE item DROP ebay_listed_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP marketplace_listings');
        $this->addSql('ALTER TABLE item ADD ebay_offer_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD ebay_listing_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD ebay_listed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
