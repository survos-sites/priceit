<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\MarketplaceListingPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers what decides whether an item may go public. The rest is a network call.
 */
final class MarketplaceListingPublisherTest extends TestCase
{
    private const CONNECTIONS = [
        'dave' => ['driver' => 'ebay', 'site' => 'EBAY_US'],
        'chijal' => ['driver' => 'mercadolibre', 'site' => 'MLM'],
    ];

    /** @param array<string, array{driver: string, site: string}> $connections */
    private function publisher(array $connections = self::CONNECTIONS): MarketplaceListingPublisher
    {
        return new MarketplaceListingPublisher(
            em: $this->createMock(EntityManagerInterface::class),
            publicUrl: 'https://mac-priceit.scanstationai.work',
            connections: $connections,
        );
    }

    private function item(CaptureProfile $profile = CaptureProfile::Auction): Item
    {
        $item = new Item('client-abc', $profile);
        $item->setTitle('Lote 5 postales antiguas de animales');
        $item->setPrice('80.00');

        return $item;
    }

    public function testWithNoConnectionsNothingIsListable(): void
    {
        self::assertFalse($this->publisher([])->isAvailable());
        // Names the connection that is missing, rather than a generic "not
        // configured" that leaves you guessing which one.
        self::assertContains(
            'No marketplace connection named "dave".',
            $this->publisher([])->blockers($this->item(), 'dave'),
        );
    }

    public function testLoanClosetEquipmentIsNeverListed(): void
    {
        // Lent out free; the profile already tells the model not to price it.
        self::assertContains(
            'Medical equipment items are not listed for sale.',
            $this->publisher()->blockers($this->item(CaptureProfile::MedicalEquipment), 'dave'),
        );
    }

    public function testBlockersAreReportedPerMarketplace(): void
    {
        $item = $this->item();
        $item->recordListing('ebay', 'offer-42', 'https://www.ebay.com/itm/110586523456');

        // Listed on eBay, still listable on Mercado Libre -- the same object can
        // legitimately be on both.
        self::assertContains(
            'Already listed on ebay. Withdraw it before listing again.',
            $this->publisher()->blockers($item, 'dave'),
        );
        self::assertNotContains(
            'Already listed on mercadolibre. Withdraw it before listing again.',
            $this->publisher()->blockers($item, 'chijal'),
        );
    }

    public function testMissingTitleOrPriceBlocks(): void
    {
        $noTitle = $this->item();
        $noTitle->setTitle(null);
        self::assertContains('No title.', $this->publisher()->blockers($noTitle, 'dave'));

        $free = $this->item();
        $free->setPrice('0.00');
        self::assertContains('No confirmed price.', $this->publisher()->blockers($free, 'dave'));
    }

    public function testPhotosMustBeFetchableOverHttps(): void
    {
        // Both providers fetch the image themselves, so a local path publishes a
        // listing with a broken picture.
        self::assertContains(
            'No photo with a publicly reachable https URL — marketplaces fetch images themselves.',
            $this->publisher()->blockers($this->item(), 'dave'),
        );
    }

    public function testListingsAreRecordedAndForgottenPerProvider(): void
    {
        $item = $this->item();

        self::assertFalse($item->isListedOn('ebay'));

        $item->recordListing('ebay', 'offer-42', 'https://www.ebay.com/itm/110586523456');
        $item->recordListing('mercadolibre', 'MLM1234567890', 'https://articulo.mercadolibre.com.mx/MLM-1234567890');

        self::assertTrue($item->isListedOn('ebay'));
        self::assertTrue($item->isListedOn('mercadolibre'));
        // The offer id, not the listing id: it is what eBay's writes accept.
        self::assertSame('offer-42', $item->getListingExternalId('ebay'));
        self::assertNotNull($item->getListedAt('ebay'));

        $item->forgetListing('ebay');
        self::assertFalse($item->isListedOn('ebay'));
        self::assertTrue($item->isListedOn('mercadolibre'), 'withdrawing one must not touch the other');
    }

    public function testUnknownConnectionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->publisher()->providerFor('nope');
    }

    public function testTheSkuIsTheClientId(): void
    {
        self::assertSame('client-abc', $this->item()->getSku());
    }
}
