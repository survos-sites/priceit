<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\EbayListingPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the part that decides whether an item may go public, which is the part
 * worth being sure about: everything else is a network call.
 */
final class EbayListingPublisherTest extends TestCase
{
    private function publisher(?string $connection = null): EbayListingPublisher
    {
        return new EbayListingPublisher(
            em: $this->createMock(EntityManagerInterface::class),
            publicUrl: 'https://priceit.example.org',
            connectionName: $connection,
        );
    }

    private function item(CaptureProfile $profile = CaptureProfile::Auction): Item
    {
        $item = new Item('client-abc', $profile);
        $item->setTitle('Lot of 5 Vintage Animal Postcards');
        $item->setPrice('12.00');

        return $item;
    }

    public function testUnconfiguredIsUnavailableRatherThanFatal(): void
    {
        // An installation with no eBay credentials must still boot and render.
        $publisher = $this->publisher();

        self::assertFalse($publisher->isAvailable());
        self::assertContains(
            'eBay is not configured (set EBAY_CONNECTION and the survos_marketplace connection).',
            $publisher->blockers($this->item()),
        );
    }

    public function testLoanClosetEquipmentIsNeverListed(): void
    {
        // Medical equipment is lent out free; the profile already tells the model
        // not to price it, so offering to sell it would be incoherent.
        $blockers = $this->publisher('dave')->blockers($this->item(CaptureProfile::MedicalEquipment));

        self::assertContains('Medical equipment items are not listed on eBay.', $blockers);
    }

    public function testAnItemWithNoConfirmedPriceIsBlocked(): void
    {
        $item = $this->item();
        $item->setPrice(null);

        self::assertContains('No confirmed price.', $this->publisher('dave')->blockers($item));
    }

    public function testAZeroPriceIsBlockedNotTreatedAsFree(): void
    {
        $item = $this->item();
        $item->setPrice('0.00');

        self::assertContains('No confirmed price.', $this->publisher('dave')->blockers($item));
    }

    public function testAnUntitledItemIsBlocked(): void
    {
        $item = $this->item();
        $item->setTitle(null);

        self::assertContains('No title.', $this->publisher('dave')->blockers($item));
    }

    public function testAlreadyListedItemsAreBlockedSoAListingIsNotDuplicated(): void
    {
        // eBay would either refuse the second offer for this SKU or, worse, accept
        // it and put the same object on sale twice.
        $item = $this->item();
        $item->recordEbayListing('offer-42', '110586523456');

        self::assertTrue($item->isListedOnEbay());
        self::assertContains(
            'Already listed on eBay. Withdraw it before listing again.',
            $this->publisher('dave')->blockers($item),
        );
    }

    public function testPhotosWithoutAPublicHttpsUrlAreBlocked(): void
    {
        // eBay fetches the image and keeps pointing at it, so a local path
        // publishes a listing with a broken picture.
        self::assertContains(
            'No photo with a publicly reachable URL — eBay fetches images over https.',
            $this->publisher('dave')->blockers($this->item()),
        );
    }

    public function testTheSkuIsTheClientIdRatherThanASecondIdentity(): void
    {
        self::assertSame('client-abc', $this->item()->getSku());
    }

    public function testTheListingUrlIsBuiltFromTheListingIdNotTheOfferId(): void
    {
        $item = $this->item();

        self::assertNull($item->getEbayUrl());

        $item->recordEbayListing('offer-42', '110586523456');

        self::assertSame('https://www.ebay.com/itm/110586523456', $item->getEbayUrl());
        self::assertSame('offer-42', $item->getEbayOfferId(), 'the offer id is what withdraw() needs');
        self::assertNotNull($item->getEbayListedAt());
    }
}
