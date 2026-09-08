<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\PriceLabelService;
use PHPUnit\Framework\TestCase;

final class CaptureProfileTest extends TestCase
{
    public function testEverythingForSaleIsPricedAndLoanEquipmentIsNot(): void
    {
        self::assertTrue(CaptureProfile::GarageSale->wantsPrice());
        self::assertTrue(CaptureProfile::Resale->wantsPrice());
        self::assertFalse(CaptureProfile::MedicalEquipment->wantsPrice());
    }

    public function testOnlyMedicalEquipmentGetsAQrCodeAndAnAssetNumber(): void
    {
        foreach ([CaptureProfile::GarageSale, CaptureProfile::Resale] as $forSale) {
            self::assertFalse($forSale->wantsQrCode());
            self::assertFalse($forSale->assignsAssetNumber());
            self::assertFalse($forSale->publishesToQuickbase());
        }

        self::assertTrue(CaptureProfile::MedicalEquipment->wantsQrCode());
        self::assertTrue(CaptureProfile::MedicalEquipment->assignsAssetNumber());
        self::assertTrue(CaptureProfile::MedicalEquipment->publishesToQuickbase());
    }

    public function testOnlyResaleItemsAreListedOnline(): void
    {
        // The whole point of the split: a garage-sale price is deliberately low, so
        // putting it on eBay undersells. Only Resale goes online.
        self::assertTrue(CaptureProfile::Resale->listsOnMarketplace());
        self::assertFalse(CaptureProfile::GarageSale->listsOnMarketplace());
        // Loan-closet equipment is lent out free; selling it would be incoherent.
        self::assertFalse(CaptureProfile::MedicalEquipment->listsOnMarketplace());
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

    public function testAGarageSaleLabelCarriesThePriceAndNoQrCode(): void
    {
        $zpl = (new PriceLabelService())->buildZpl(
            $this->item(CaptureProfile::GarageSale, price: '24.00'),
            'https://example.test/admin/items/142',
        );

        self::assertStringContainsString('$24', $zpl);
        self::assertStringNotContainsString('^BQN', $zpl, 'an auction tag has no QR code');
    }

    public function testALoanClosetLabelCarriesTheAssetNumberInBothPlacesAndNoPrice(): void
    {
        $zpl = (new PriceLabelService())->buildZpl(
            $this->item(CaptureProfile::MedicalEquipment),
            'https://example.test/e/LC-00142',
        );

        // The scanned URL has to end in the same asset number printed beside it, or a phone
        // and a volunteer reading the label aloud would disagree about which item this is.
        self::assertStringContainsString('^FDLC-00142^FS', $zpl);
        self::assertStringContainsString('^FDQA,https://example.test/e/LC-00142^FS', $zpl);
        self::assertStringNotContainsString('$', $zpl, 'nothing in the closet is for sale');
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
        self::assertSame(CaptureProfile::GarageSale, (new Item('c-1'))->getProfile());
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
