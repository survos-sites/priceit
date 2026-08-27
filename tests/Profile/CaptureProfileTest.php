<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\PriceLabelService;
use PHPUnit\Framework\TestCase;

final class CaptureProfileTest extends TestCase
{
    public function testAuctionPricesAndMedicalDoesNot(): void
    {
        self::assertTrue(CaptureProfile::Auction->wantsPrice());
        self::assertFalse(CaptureProfile::MedicalEquipment->wantsPrice());
    }

    public function testOnlyMedicalEquipmentGetsAQrCodeAndAnAssetNumber(): void
    {
        self::assertFalse(CaptureProfile::Auction->wantsQrCode());
        self::assertFalse(CaptureProfile::Auction->assignsAssetNumber());
        self::assertFalse(CaptureProfile::Auction->publishesToQuickbase());

        self::assertTrue(CaptureProfile::MedicalEquipment->wantsQrCode());
        self::assertTrue(CaptureProfile::MedicalEquipment->assignsAssetNumber());
        self::assertTrue(CaptureProfile::MedicalEquipment->publishesToQuickbase());
    }

    public function testTheMedicalPromptRefusesToAskForAValue(): void
    {
        // The closet lends equipment at no charge. A prompt that invites a price invites a
        // number that would then be printed on the label of something being given away.
        $guidance = CaptureProfile::MedicalEquipment->promptGuidance();

        self::assertStringContainsString('Do not estimate', $guidance);
        self::assertStringContainsString('nothing here is for sale', $guidance);
        self::assertStringNotContainsString('garage sale', $guidance);
    }

    public function testAnAuctionLabelCarriesThePriceAndNoQrCode(): void
    {
        $zpl = (new PriceLabelService())->buildZpl(
            $this->item(CaptureProfile::Auction, price: '24.00'),
            'https://example.test/admin/items/142',
        );

        self::assertStringContainsString('$24', $zpl);
        self::assertStringNotContainsString('^BQN', $zpl, 'an auction tag has no QR code');
    }

    public function testALoanClosetLabelCarriesTheAssetNumberInBothPlacesAndNoPrice(): void
    {
        $zpl = (new PriceLabelService())->buildZpl(
            $this->item(CaptureProfile::MedicalEquipment),
            'https://example.test/admin/items/142',
        );

        // Printed digits and scanned code must be the same string, or a volunteer reading the
        // label aloud and a phone scanning it would disagree about which item this is.
        self::assertStringContainsString('^FDLC-00142^FS', $zpl);
        self::assertStringContainsString('^FDQA,LC-00142^FS', $zpl);
        self::assertStringNotContainsString('$', $zpl, 'nothing in the closet is for sale');
        self::assertStringNotContainsString('example.test', $zpl, 'the code is the key, not a URL');
    }

    public function testAnAssetNumberIsMintedOnceAndNeverChanges(): void
    {
        $item = $this->item(CaptureProfile::MedicalEquipment);
        $first = $item->getAssetNumber();

        // A reprint must reproduce the same label, not issue the item a second identity.
        self::assertSame($first, $item->assignAssetNumber());
        self::assertSame('LC-00142', $first);
    }

    public function testItemsDefaultToAuctionSoExistingCapturesAreUnchanged(): void
    {
        self::assertSame(CaptureProfile::Auction, (new Item('c-1'))->getProfile());
    }

    private function item(CaptureProfile $profile, ?string $price = null): Item
    {
        $item = new Item('c-'.$profile->value, $profile);
        (new \ReflectionProperty(Item::class, 'id'))->setValue($item, 142);
        $item->setTitle('Drive Medical folding walker');
        $item->setDescription('Aluminum folding walker, 5in wheels, adjustable height.');

        if (null !== $price) {
            $item->setPrice($price);
        }

        if ($profile->assignsAssetNumber()) {
            $item->assignAssetNumber();
        }

        return $item;
    }
}
