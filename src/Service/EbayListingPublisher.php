<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Survos\MarketplaceBundle\Service\MarketplaceService;
use Survos\MarketplaceContracts\Contract\MarketplaceAdapterInterface;
use Survos\MarketplaceContracts\Exception\ListingRejectedException;
use Survos\MarketplaceContracts\Model\ListingCondition;
use Survos\MarketplaceContracts\Model\ListingDraft;
use Survos\MarketplaceContracts\Model\ListingImage;
use Survos\MarketplaceContracts\Model\ListingViolation;
use Survos\MarketplaceContracts\Model\Money;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Puts a priced auction lot on eBay.
 *
 * Mirrors QuickbaseInventoryPublisher: nullable dependencies and isAvailable(), so
 * an installation with no eBay credentials runs normally with the button disabled
 * rather than exploding at boot.
 *
 * The category and its required aspects are resolved from eBay at publish time
 * rather than stored. eBay reshuffles categories, and a stale category id fails at
 * publish with an error that names neither the category nor the fix.
 */
final class EbayListingPublisher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private readonly string $publicUrl = '',
        #[Autowire('%env(default::EBAY_CONNECTION)%')]
        private readonly ?string $connectionName = null,
        private readonly ?MarketplaceService $marketplace = null,
        private readonly ?StorageInterface $storage = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->marketplace
            && null !== $this->connectionName
            && '' !== $this->connectionName;
    }

    /**
     * Why this item cannot go to eBay yet, in the order a person would fix them.
     *
     * Separate from publish() so the admin screen can explain a disabled button
     * instead of offering one that fails.
     *
     * @return list<string>
     */
    public function blockers(Item $item): array
    {
        $blockers = [];

        if (!$this->isAvailable()) {
            $blockers[] = 'eBay is not configured (set EBAY_CONNECTION and the survos_marketplace connection).';
        }
        if (!$item->getProfile()->listsOnEbay()) {
            $blockers[] = sprintf('%s items are not listed on eBay.', $item->getProfile()->label());
        }
        if ($item->isListedOnEbay()) {
            $blockers[] = 'Already listed on eBay. Withdraw it before listing again.';
        }
        if (null === $item->getTitle() || '' === $item->getTitle()) {
            $blockers[] = 'No title.';
        }
        if (null === $item->getPrice() || 0.0 >= (float) $item->getPrice()) {
            $blockers[] = 'No confirmed price.';
        }
        if ([] === $this->imageUrls($item)) {
            $blockers[] = 'No photo with a publicly reachable URL — eBay fetches images over https.';
        }

        return $blockers;
    }

    public function adapter(): MarketplaceAdapterInterface
    {
        $marketplace = $this->marketplace
            ?? throw new \LogicException('survos/marketplace-bundle is not installed.');

        return $marketplace->adapter((string) $this->connectionName);
    }

    /**
     * Build the draft, resolving the category and its aspects from eBay.
     *
     * Nothing is sent here. The draft can be inspected, validated and shown to a
     * person before anything becomes public.
     */
    public function draft(Item $item, ?string $categoryId = null): ListingDraft
    {
        $adapter = $this->adapter();

        $draft = new ListingDraft(
            sku: $item->getSku(),
            // eBay caps titles at 80. The AI writes for a price sticker, not a
            // search result, so this is usually well under.
            title: mb_substr((string) $item->getTitle(), 0, $adapter->limits()->titleMaxLength),
            description: (string) ($item->getDescription() ?? $item->getTitle()),
            price: Money::fromDecimal((string) $item->getPrice(), 'USD'),
            // A garage-sale lot is second-hand unless someone says otherwise, and
            // over-claiming condition on eBay is how sellers get return cases.
            condition: ListingCondition::UsedGood,
            quantity: 1,
            images: array_map(
                static fn (string $url): ListingImage => new ListingImage($url),
                $this->imageUrls($item),
            ),
        );

        $categoryId ??= $adapter->suggestCategories($draft->title)[0]->categoryId ?? null;

        return null !== $categoryId ? $draft->withCategoryId($categoryId) : $draft;
    }

    /**
     * Everything eBay would reject, found before sending anything.
     *
     * @return list<ListingViolation>
     */
    public function validate(ListingDraft $draft): array
    {
        $adapter = $this->adapter();

        return [
            ...$draft->violations($adapter->limits()),
            ...(null !== $draft->categoryId
                ? $adapter->attributeSchema($draft->categoryId)->violationsFor($draft)
                : []),
        ];
    }

    /**
     * Publish, and record the ids on the item.
     *
     * @throws ListingRejectedException when eBay refuses the listing
     */
    public function publish(Item $item, ?string $categoryId = null): ListingDraft
    {
        $draft = $this->draft($item, $categoryId);

        $violations = $this->validate($draft);
        if ([] !== $violations) {
            // Refusing here rather than letting eBay refuse: the violations name the
            // field and the accepted values, and eBay's equivalent error usually does not.
            throw new ListingRejectedException('ebay', $violations);
        }

        $listing = $this->adapter()->publish($draft);

        $item->recordEbayListing(
            $listing->externalId,
            \is_string($listing->raw['listingId'] ?? null) ? $listing->raw['listingId'] : null,
        );
        $this->em->flush();

        return $draft;
    }

    public function withdraw(Item $item): void
    {
        $offerId = $item->getEbayOfferId()
            ?? throw new \LogicException(sprintf('Item %d is not listed on eBay.', (int) $item->getId()));

        $this->adapter()->withdraw($offerId);
    }

    /**
     * Photo URLs eBay can actually fetch.
     *
     * eBay pulls the image from this URL and keeps pointing at it, so a localhost
     * or file path is worse than useless -- the listing publishes with a broken
     * image. Anything not absolute https is dropped rather than sent.
     *
     * @return list<string>
     */
    private function imageUrls(Item $item): array
    {
        if (null === $this->storage) {
            return [];
        }

        $urls = [];
        foreach ($item->getPhotos() as $photo) {
            $uri = $this->storage->resolveUri($photo, 'file');
            if (null === $uri || '' === $uri) {
                continue;
            }

            $url = str_starts_with($uri, 'http')
                ? $uri
                : rtrim($this->publicUrl, '/').'/'.ltrim($uri, '/');

            if (str_starts_with($url, 'https://')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
