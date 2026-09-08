<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\Etsy\Auth\EtsyCredentials;
use Survos\Etsy\Exception\EtsyException;
use Survos\MarketplaceContracts\Contract\DraftPublisherInterface;
use Survos\MarketplaceContracts\Contract\ListingRemoverInterface;
use Survos\MarketplaceContracts\Exception\MarketplaceException;
use Survos\Etsy\Generated\ShopApi;
use Survos\Etsy\Generated\ShopListingApi;
use Survos\Etsy\Http\EtsyTransport;
use Survos\MarketplaceBundle\Token\EtsyTokenProviderFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\MarketplaceListingPublisher;

/**
 * Read-only view of a connected Etsy shop.
 *
 * Proves the whole chain end to end -- stored token, refresh, x-api-key, the
 * generated client -- without writing anything to a real seller's shop. When a
 * publish later fails, this answers "is the connection broken, or is the listing
 * wrong?", which are very different problems.
 *
 * Drafts are shown alongside active listings on purpose: a draft we created is
 * invisible on etsy.com, so this is the only place it can be seen from here.
 */
#[Route('/etsy', name: 'etsy_')]
final class EtsyShopController extends AbstractController
{
    /** @param array<string, array{driver: string, options?: array<string, mixed>}> $connections */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%survos_marketplace.connections%')]
        private readonly array $connections = [],
        private readonly ?EtsyTokenProviderFactory $tokens = null,
        private readonly ?MarketplaceListingPublisher $marketplace = null,
    ) {
    }

    /**
     * Take a draft live.
     *
     * Deliberately its own action rather than part of creating the listing: this
     * is the moment Etsy charges the listing fee, so it belongs to a person
     * clicking a button after looking at the draft, not to a scan.
     */
    #[Route('/publish/{connection}/{listingId}', name: 'publish', methods: ['POST'])]
    public function publishDraft(Request $request, string $connection, string $listingId): Response
    {
        if (!$this->isCsrfTokenValid('etsy_publish_' . $listingId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $adapter = $this->marketplace?->adapter($connection);

        if (!$adapter instanceof DraftPublisherInterface) {
            $this->addFlash('danger', sprintf('%s cannot publish drafts.', $connection));

            return $this->redirectToRoute('etsy_shop', ['connection' => $connection, 'state' => 'draft']);
        }

        try {
            $listing = $adapter->activate($listingId);
            $this->addFlash('success', sprintf(
                'Published — now live at %s',
                $listing->url ?? $listing->externalId,
            ));
        } catch (MarketplaceException|EtsyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('etsy_shop', ['connection' => $connection, 'state' => 'draft']);
    }

    /**
     * Take a listing down without destroying it.
     *
     * What a seller means by "I sold that elsewhere": the listing, its views and
     * its favourites survive and it can be relisted. Reversible from Etsy's own
     * UI, which is why it does not ask twice.
     */
    #[Route('/deactivate/{connection}/{listingId}', name: 'deactivate', methods: ['POST'])]
    public function deactivate(Request $request, string $connection, string $listingId): Response
    {
        if (!$this->isCsrfTokenValid('etsy_deactivate_' . $listingId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->marketplace?->adapter($connection)->withdraw($listingId);
            $this->addFlash('success', sprintf('Listing %s deactivated — it can be relisted later.', $listingId));
        } catch (MarketplaceException|EtsyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('etsy_shop', ['connection' => $connection, 'state' => $request->request->getString('back') ?: null]);
    }

    /**
     * Destroy the listing.
     *
     * Not the same as deactivating, and not reversible: the views, favourites and
     * the fee already paid go with it. Offered because a draft that should never
     * have existed is worth removing outright, but deliberately the less prominent
     * of the two.
     */
    #[Route('/delete/{connection}/{listingId}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, string $connection, string $listingId): Response
    {
        if (!$this->isCsrfTokenValid('etsy_delete_' . $listingId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $adapter = $this->marketplace?->adapter($connection);

        if (!$adapter instanceof ListingRemoverInterface) {
            $this->addFlash('danger', sprintf('%s cannot delete listings.', $connection));

            return $this->redirectToRoute('etsy_shop', ['connection' => $connection]);
        }

        try {
            $adapter->delete($listingId);
            $this->addFlash('success', sprintf('Listing %s deleted permanently.', $listingId));
        } catch (MarketplaceException|EtsyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('etsy_shop', ['connection' => $connection, 'state' => $request->request->getString('back') ?: null]);
    }

    #[Route('/shop/{connection}', name: 'shop', defaults: ['connection' => 'dave_etsy'])]
    public function shop(Request $request, string $connection): Response
    {
        $config = $this->connections[$connection] ?? null;

        if (null === $this->tokens || null === $config || 'etsy' !== ($config['driver'] ?? null)) {
            throw $this->createNotFoundException(sprintf('No Etsy connection named "%s".', $connection));
        }

        /** @var array<string, mixed> $options */
        $options = $config['options'] ?? [];
        $credentials = new EtsyCredentials(
            keystring: (string) ($options['keystring'] ?? ''),
            sharedSecret: isset($options['shared_secret']) ? (string) $options['shared_secret'] : null,
        );

        $transport = new EtsyTransport(
            $this->httpClient,
            $credentials,
            $this->tokens->forConnection($connection, $credentials),
        );

        $shopId = (int) ($options['shop_id'] ?? 0);
        $state = $request->query->getString('state') ?: null;

        try {
            $shop = 0 !== $shopId ? (new ShopApi($transport))->getShop($shopId) : null;
            $listings = 0 !== $shopId
                ? (new ShopListingApi($transport))->getListingsByShop($shopId, $state, 50)
                : null;
            $error = null;
        } catch (EtsyException $e) {
            // Surfaced rather than thrown: a 401 here means the token needs
            // re-consent, and that is a thing to read, not a stack trace.
            $shop = $listings = null;
            $error = $e->getMessage();
        }

        return $this->render('etsy/shop.html.twig', [
            'connection' => $connection,
            'shop' => $shop,
            'listings' => $listings,
            'state' => $state,
            'error' => $error,
        ]);
    }
}
