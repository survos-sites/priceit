<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\Etsy\Auth\EtsyCredentials;
use Survos\Etsy\Exception\EtsyException;
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
    ) {
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
