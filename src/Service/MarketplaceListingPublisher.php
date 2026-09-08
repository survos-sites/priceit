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
use Survos\MarketplaceContracts\Model\PublishedListing;
use App\Repository\ItemRepository;
use Survos\MarketplaceContracts\Contract\ListingRemoverInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Lists an item on any configured marketplace.
 *
 * Keyed by connection name rather than by provider, because the same provider can
 * appear twice -- a sandbox account and a live one, or two sellers.
 *
 * The category and its attributes are resolved at publish time rather than stored.
 * Both marketplaces reshuffle categories, and a stale id fails at publish with an
 * error that names neither the category nor the fix.
 */
final class MarketplaceListingPublisher
{
    /** @param array<string, array{driver: string, site: string}> $connections */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private readonly string $publicUrl = '',
        #[Autowire('%survos_marketplace.connections%')]
        private readonly array $connections = [],
        private readonly ?MarketplaceService $marketplace = null,
        private readonly ?StorageInterface $storage = null,
        private readonly ?ItemRepository $items = null,
    ) {
    }

    /**
     * Everything publish() would do, without sending anything.
     *
     * Worth having as its own command because the marketplaces differ in how much
     * they will tell you before you commit: this resolves the category, builds the
     * draft and validates it, so a bad category or a missing required attribute
     * shows up here rather than as a rejected listing on a real seller's shop.
     */
    #[AsCommand('marketplace:preview', 'Resolve a category and validate an item, without publishing')]
    public function previewCommand(
        SymfonyStyle $io,
        #[Argument('Connection name')] string $connection,
        #[Argument('Item id')] int $itemId,
        #[Option('Category / taxonomy id, skipping local matching')] ?string $category = null,
    ): int {
        $item = $this->items?->find($itemId);
        if (null === $item) {
            $io->error(sprintf('No item %d.', $itemId));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['item' => sprintf('%d — %s', $item->getId(), $item->getTitle() ?? '(untitled)')],
            ['price' => (string) $item->getPrice()],
            ['profile' => $item->getProfile()->value],
            ['photos' => (string) count($item->getPhotos())],
        );

        $blockers = $this->blockers($item, $connection);
        if ([] !== $blockers) {
            $io->error($blockers);

            return Command::FAILURE;
        }
        $io->success('No blockers.');

        $adapter = $this->adapter($connection);
        $rows = [];
        foreach ($adapter->suggestCategories((string) $item->getTitle()) as $s) {
            $rows[] = [$s->categoryId, $s->name, $s->confidence ?? '-', $s->breadcrumb()];
        }
        $io->section('Category candidates');
        $io->table(['id', 'name', 'score', 'path'], $rows);

        $draft = $this->draft($item, $connection, $category);
        $io->section(sprintf('Draft (category %s)', $draft->categoryId ?? 'NONE'));
        $io->definitionList(
            ['title' => $draft->title],
            ['price' => (string) $draft->price],
            ['images' => implode("\n", array_map(static fn ($i): string => $i->url, $draft->images))],
        );

        $violations = $this->validate($draft, $connection);
        if ([] === $violations) {
            $io->success('Validates clean — publish would be accepted.');

            return Command::SUCCESS;
        }

        $io->section('Violations');
        $io->listing(array_map(static fn ($v): string => (string) $v, $violations));

        return Command::FAILURE;
    }

    /** Publish for real. On Etsy this creates a DRAFT; the seller publishes it themselves. */
    #[AsCommand('marketplace:publish', 'Publish an item to a marketplace')]
    public function publishCommand(
        SymfonyStyle $io,
        #[Argument('Connection name')] string $connection,
        #[Argument('Item id')] int $itemId,
        /**
         * Etsy has no category-suggestion endpoint, so the adapter scores word
         * overlap against the taxonomy tree locally — and a title like "Cockatoo.
         * Parrot Jungle, Miami, Florida" shares no words with any node name.
         * Passing the id is both the escape hatch and, for a known category like
         * postcards, simply the right answer.
         */
        #[Option('Category / taxonomy id, skipping local matching')] ?string $category = null,
    ): int {
        $item = $this->items?->find($itemId);
        if (null === $item) {
            $io->error(sprintf('No item %d.', $itemId));

            return Command::FAILURE;
        }

        try {
            $listing = $this->publish($item, $connection, $category);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s: %s', $listing->provider, $listing->url ?? $listing->externalId));
        $io->definitionList(
            ['external id' => $listing->externalId],
            ['state' => (string) ($listing->raw['state'] ?? '-')],
            ['images uploaded' => (string) ($listing->raw['imagesUploaded'] ?? '-')],
        );

        foreach ((array) ($listing->raw['imageErrors'] ?? []) as $err) {
            $io->warning((string) $err);
        }

        return Command::SUCCESS;
    }

    public function isAvailable(): bool
    {
        return null !== $this->marketplace && [] !== $this->connections;
    }

    /** @return array<string, array{driver: string, site: string}> */
    public function connections(): array
    {
        return $this->connections;
    }

    public function adapter(string $connection): MarketplaceAdapterInterface
    {
        $marketplace = $this->marketplace
            ?? throw new \LogicException('survos/marketplace-bundle is not installed.');

        return $marketplace->adapter($connection);
    }

    /** The provider behind a connection, e.g. `ebay`. Used as the listing map key. */
    public function providerFor(string $connection): string
    {
        return $this->connections[$connection]['driver']
            ?? throw new \LogicException(sprintf('No marketplace connection named "%s".', $connection));
    }

    /**
     * Why this item cannot go to this marketplace yet, in the order a person would
     * fix them. Separate from publish() so a disabled button can explain itself.
     *
     * @return list<string>
     */
    public function blockers(Item $item, string $connection): array
    {
        $blockers = [];

        // NOT an early return. The item-level reasons hold whether or not a
        // marketplace is wired up, and hiding them behind "not configured" means a
        // person fixes the config and then discovers the price was missing all along.
        if (null === $this->marketplace) {
            $blockers[] = 'survos/marketplace-bundle is not installed.';
        }
        if (!isset($this->connections[$connection])) {
            $blockers[] = sprintf('No marketplace connection named "%s".', $connection);

            // Everything below needs to know which provider this is.
            return $blockers;
        }

        $provider = $this->providerFor($connection);

        if (!$item->getProfile()->listsOnMarketplace()) {
            $blockers[] = sprintf(
                '%s items are not listed online. Change the profile to "Resale" if it should be.',
                $item->getProfile()->label(),
            );
        }
        if ($item->isListedOn($connection)) {
            $blockers[] = sprintf('Already listed for "%s". Withdraw it before listing again.', $connection);
        }
        if (null === $item->getTitle() || '' === $item->getTitle()) {
            $blockers[] = 'No title.';
        }
        if (null === $item->getPrice() || 0.0 >= (float) $item->getPrice()) {
            $blockers[] = 'No confirmed price.';
        }
        if ([] === $this->imageUrls($item)) {
            $blockers[] = 'No photo with a publicly reachable https URL — marketplaces fetch images themselves.';
        }

        return $blockers;
    }

    /**
     * Build the draft, resolving the category from the marketplace.
     *
     * Nothing is sent. The draft can be inspected and validated before anything
     * becomes public.
     */
    public function draft(Item $item, string $connection, ?string $categoryId = null): ListingDraft
    {
        $adapter = $this->adapter($connection);
        $limits = $adapter->limits();

        $draft = new ListingDraft(
            sku: $item->getSku(),
            // Respect the marketplace's cap rather than the label's: Mercado Libre
            // allows 60 where eBay allows 80.
            title: mb_substr((string) $item->getTitle(), 0, $limits->titleMaxLength),
            description: (string) ($item->getDescription() ?? $item->getTitle()),
            price: Money::fromDecimal((string) $item->getPrice(), $this->currencyFor($connection)),
            // Second-hand unless someone says otherwise: over-claiming condition is
            // how sellers collect return cases.
            condition: ListingCondition::UsedGood,
            quantity: 1,
            images: array_map(
                static fn (string $url): ListingImage => new ListingImage($url),
                $this->imageUrls($item),
            ),
            locale: $this->localeFor($connection),
            attributes: $item->getAttributes(),
        );

        // Etsy publishes no category-suggestion endpoint, so its adapter scores word
        // overlap against the taxonomy locally -- and a title like "Cockatoo. Parrot
        // Jungle, Miami, Florida" shares no word with any node name. The item type
        // ("postcard") is what actually names the category, so try it first and keep
        // the title as the fallback.
        if (null === $categoryId) {
            $type = $item->getAttributes()['type'] ?? null;
            $categoryId = (is_string($type) && '' !== $type
                    ? $adapter->suggestCategories($type)[0]->categoryId ?? null
                    : null)
                ?? $adapter->suggestCategories($draft->title)[0]->categoryId ?? null;
        }

        return null !== $categoryId ? $draft->withCategoryId($categoryId) : $draft;
    }

    /**
     * Everything the marketplace would reject, found before sending anything.
     *
     * @return list<ListingViolation>
     */
    public function validate(ListingDraft $draft, string $connection): array
    {
        $adapter = $this->adapter($connection);

        return [
            ...$draft->violations($adapter->limits()),
            ...(null !== $draft->categoryId
                ? $adapter->attributeSchema($draft->categoryId)->violationsFor($draft)
                : []),
        ];
    }

    /** @throws ListingRejectedException when the marketplace refuses the listing */
    public function publish(Item $item, string $connection, ?string $categoryId = null): PublishedListing
    {
        $draft = $this->draft($item, $connection, $categoryId);

        $violations = $this->validate($draft, $connection);
        if ([] !== $violations) {
            // Refusing here rather than letting the marketplace refuse: the
            // violations name the field and the accepted values, and the providers'
            // equivalent errors usually do not.
            throw new ListingRejectedException($this->providerFor($connection), $violations);
        }

        $listing = $this->adapter($connection)->publish($draft);

        $item->recordListing($connection, $listing->externalId, $listing->url);
        $this->em->flush();

        return $listing;
    }

    #[AsCommand('marketplace:withdraw', 'Stop selling a listed item, or destroy the listing outright')]
    public function withdrawCommand(
        SymfonyStyle $io,
        #[Argument('Connection name')] string $connection,
        #[Argument('Item id')] int $itemId,
        /**
         * withdraw() is recoverable everywhere it exists; delete() is not, and takes
         * the listing's views and favourites with it. Reach for it only on a listing
         * that should never have existed -- a draft published from bad data, say.
         */
        #[Option('Destroy the listing permanently instead of deactivating it')] bool $delete = false,
    ): int {
        $item = $this->items?->find($itemId);
        if (null === $item) {
            $io->error(sprintf('No item %d.', $itemId));

            return Command::FAILURE;
        }

        $externalId = $item->getListingExternalId($connection);
        if (null === $externalId) {
            $io->warning(sprintf('Item %d is not listed for "%s". Nothing to do.', $itemId, $connection));

            return Command::SUCCESS;
        }

        try {
            $this->withdraw($item, $connection, $delete);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s %s on %s.', $delete ? 'Deleted' : 'Deactivated', $externalId, $connection));

        return Command::SUCCESS;
    }

    /**
     * @param bool $delete destroy the listing rather than deactivating it; only
     *                     possible where the adapter implements ListingRemoverInterface
     */
    public function withdraw(Item $item, string $connection, bool $delete = false): void
    {
        $externalId = $item->getListingExternalId($connection)
            ?? throw new \LogicException(sprintf('Item %d is not listed for "%s".', (int) $item->getId(), $connection));

        $adapter = $this->adapter($connection);

        if ($delete) {
            if (!$adapter instanceof ListingRemoverInterface) {
                throw new \LogicException(sprintf('"%s" cannot delete listings; withdraw instead.', $connection));
            }
            $adapter->delete($externalId);
        } else {
            $adapter->withdraw($externalId);
        }

        $item->forgetListing($connection);
        $this->em->flush();
    }

    /**
     * Mercado Libre prices in the site's own currency; a Mexican listing quoted in
     * USD is rejected. eBay US is USD.
     */
    private function currencyFor(string $connection): string
    {
        $site = $this->connections[$connection]['site'] ?? '';

        return match ($site) {
            'MLM' => 'MXN', 'MLA' => 'ARS', 'MLB' => 'BRL',
            'MLC' => 'CLP', 'MCO' => 'COP', 'MPE' => 'PEN', 'MLU' => 'UYU',
            default => 'USD',
        };
    }

    private function localeFor(string $connection): string
    {
        $site = $this->connections[$connection]['site'] ?? '';

        return match ($site) {
            'MLM' => 'es-MX', 'MLA' => 'es-AR', 'MLB' => 'pt-BR',
            'MLC' => 'es-CL', 'MCO' => 'es-CO', 'MPE' => 'es-PE', 'MLU' => 'es-UY',
            default => 'en-US',
        };
    }

    /**
     * Photo URLs a marketplace can actually fetch.
     *
     * Both providers pull the image themselves, so a loopback or file path is worse
     * than useless -- it publishes a listing with a broken picture. Anything not
     * absolute https is dropped rather than sent.
     *
     * @return list<string>
     */
    private function imageUrls(Item $item): array
    {
        $urls = [];
        foreach ($item->getPhotos() as $photo) {
            // An imported scan already lives in S3 behind imgproxy with a durable
            // public URL. Vich knows nothing about it, and re-hosting it here would
            // duplicate storage for no gain.
            if ($photo->isExternal()) {
                $url = (string) $photo->getSourceUrl();
                if (str_starts_with($url, 'https://')) {
                    $urls[] = $url;
                }

                continue;
            }

            $uri = $this->storage?->resolveUri($photo, 'file');
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
