<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record where an item ended up on eBay.
 *
 * The offer id is the handle eBay's write operations accept (including withdrawing
 * the listing); the listing id is the public one that appears in an ebay.com/itm/
 * URL. Both are kept because neither substitutes for the other.
 */
final class Version20260907180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add eBay listing columns to item.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD ebay_offer_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD ebay_listing_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD ebay_listed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN item.ebay_listed_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP ebay_offer_id');
        $this->addSql('ALTER TABLE item DROP ebay_listing_id');
        $this->addSql('ALTER TABLE item DROP ebay_listed_at');
    }
}
