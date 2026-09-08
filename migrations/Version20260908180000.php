<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Split the "auction" profile into garage_sale and resale.
 *
 * "Auction lot" answered two different questions with one prompt: what clears a
 * folding table on Saturday, and what a thing actually fetches on eBay. The first
 * answer is deliberately low, so listing an auction-profile item online undersold
 * it every time.
 *
 * Existing rows become garage_sale, which is what they were: the old prompt asked
 * for garage-sale pricing explicitly. Anything meant for resale gets reprofiled by
 * a human, because only a human knows which items those are.
 */
final class Version20260908180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the auction capture profile to garage_sale.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE item SET profile = 'garage_sale' WHERE profile = 'auction'");
        $this->addSql("ALTER TABLE item ALTER COLUMN profile SET DEFAULT 'garage_sale'");
    }

    public function down(Schema $schema): void
    {
        // resale has no pre-split equivalent; it collapses back into auction.
        $this->addSql("UPDATE item SET profile = 'auction' WHERE profile IN ('garage_sale', 'resale')");
        $this->addSql("ALTER TABLE item ALTER COLUMN profile SET DEFAULT 'auction'");
    }
}
