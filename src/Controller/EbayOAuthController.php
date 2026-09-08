<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\Ebay\Auth\EbayScope;
use Survos\MarketplaceBundle\Token\EbayTokenProviderFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connect a seller's eBay account, once.
 *
 * The seller never gives us a password. They click /ebay/connect, log in on eBay's
 * own site, approve the app, and eBay hands back a code at /ebay/callback which we
 * trade for a refresh token good for about 18 months. They can revoke it themselves
 * at any time from their eBay account settings.
 */
#[Route('/ebay', name: 'ebay_')]
final class EbayOAuthController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::EBAY_CONNECTION)%')]
        private readonly ?string $connectionName = null,
        #[Autowire('%env(EBAY_CLIENT_ID)%')]
        private readonly string $clientId = '',
        private readonly ?EbayTokenProviderFactory $ebay = null,
    ) {
    }

    #[Route('/connect', name: 'connect')]
    public function connect(Request $request): Response
    {
        $ebay = $this->available();

        // Round-tripped by eBay and checked on the way back, so a stray callback
        // cannot bind someone else's eBay account to this connection.
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('ebay_oauth_state', $state);

        return $this->redirect($ebay->oauth()->consentUrl(EbayScope::forListing(), $state));
    }

    #[Route('/callback', name: 'callback')]
    public function callback(Request $request): Response
    {
        $ebay = $this->available();

        if ($error = $request->query->getString('error')) {
            $this->addFlash('danger', sprintf(
                'eBay declined: %s. %s',
                $error,
                $request->query->getString('error_description'),
            ));

            return $this->redirectToRoute('admin_item_index');
        }

        $expected = $request->getSession()->get('ebay_oauth_state');
        $request->getSession()->remove('ebay_oauth_state');

        if (!\is_string($expected) || !hash_equals($expected, $request->query->getString('state'))) {
            throw $this->createAccessDeniedException('eBay OAuth state mismatch.');
        }

        $code = $request->query->getString('code');
        if ('' === $code) {
            $this->addFlash('danger', 'eBay sent no authorization code.');

            return $this->redirectToRoute('admin_item_index');
        }

        // The code arrives URL-encoded and is single-use, valid for minutes.
        $token = $ebay->oauth()->exchangeCode(urldecode($code));
        $ebay->store()->save(
            (string) $this->connectionName,
            EbayTokenProviderFactory::toNeutral($token),
        );

        $this->addFlash('success', sprintf(
            'eBay connected. Access token expires %s; refresh token %s.',
            $token->expiresAt->format('j M Y, g:ia'),
            null !== $token->refreshTokenExpiresAt
                ? 'valid until '.$token->refreshTokenExpiresAt->format('j M Y')
                : 'stored',
        ));

        return $this->redirectToRoute('admin_item_index');
    }

    private function available(): EbayTokenProviderFactory
    {
        // Same reason as the Mercado Libre flow: an empty client_id would redirect
        // the seller to eBay with a request eBay cannot honour.
        if (null === $this->ebay || '' === $this->clientId
            || null === $this->connectionName || '' === $this->connectionName) {
            throw $this->createNotFoundException(
                'eBay is not configured. Set EBAY_CLIENT_ID, EBAY_CLIENT_SECRET, EBAY_RU_NAME and EBAY_CONNECTION.',
            );
        }

        return $this->ebay;
    }
}
