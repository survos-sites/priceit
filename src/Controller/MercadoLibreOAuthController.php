<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\MarketplaceBundle\Token\MercadoLibreTokenProviderFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connect a Mercado Libre seller, once.
 *
 * Same shape as the eBay flow, with one difference that matters downstream:
 * Mercado Libre refresh tokens are single use and rotate on every refresh, so the
 * token written here is only the first of a chain. Losing a later one means the
 * seller has to come back through this route.
 */
#[Route('/meli', name: 'meli_')]
final class MercadoLibreOAuthController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::MELI_CONNECTION)%')]
        private readonly ?string $connectionName = null,
        #[Autowire('%env(MELI_CLIENT_ID)%')]
        private readonly string $clientId = '',
        private readonly ?MercadoLibreTokenProviderFactory $meli = null,
    ) {
    }

    /**
     * One app, many sellers. chijal and Marcela share the same Mercado Libre
     * application -- the client id identifies the software, not the seller -- and
     * differ only in which token the callback stores.
     *
     * The connection travels in the SESSION rather than the URL because Mercado
     * Libre matches the registered redirect URI character for character. A
     * per-connection callback path would mean registering one redirect per seller.
     */
    #[Route('/connect/{connection}', name: 'connect')]
    public function connect(Request $request, ?string $connection = null): Response
    {
        $meli = $this->available();
        $connection ??= (string) $this->connectionName;

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('meli_oauth_state', $state);
        $request->getSession()->set('meli_oauth_connection', $connection);

        return $this->redirect($meli->oauth()->consentUrl($state));
    }

    #[Route('/callback', name: 'callback')]
    public function callback(Request $request): Response
    {
        $meli = $this->available();

        if ($error = $request->query->getString('error')) {
            $this->addFlash('danger', sprintf(
                'Mercado Libre declined: %s. %s',
                $error,
                $request->query->getString('message'),
            ));

            return $this->redirectToRoute('admin_item_index');
        }

        $expected = $request->getSession()->get('meli_oauth_state');
        $connection = $request->getSession()->get('meli_oauth_connection');
        $request->getSession()->remove('meli_oauth_state');
        $request->getSession()->remove('meli_oauth_connection');

        if (!\is_string($expected) || !hash_equals($expected, $request->query->getString('state'))) {
            throw $this->createAccessDeniedException('Mercado Libre OAuth state mismatch.');
        }

        $code = $request->query->getString('code');
        if ('' === $code) {
            $this->addFlash('danger', 'Mercado Libre sent no authorization code.');

            return $this->redirectToRoute('admin_item_index');
        }

        $token = $meli->oauth()->exchangeCode($code);

        if (null === $token->refreshToken) {
            // Without offline_access the access token lasts six hours and cannot be
            // renewed, so this would silently start failing this evening.
            $this->addFlash('warning',
                'Connected, but Mercado Libre returned no refresh token — the app is probably '
                . 'missing the offline_access scope. Add it and reconnect, or this stops working in 6 hours.',
            );
        }

        $connection = \is_string($connection) && '' !== $connection
            ? $connection
            : (string) $this->connectionName;

        $meli->store()->save($connection, MercadoLibreTokenProviderFactory::toNeutral($token));

        $this->addFlash('success', sprintf(
            'Mercado Libre connected for "%s" as seller %s. Access token expires %s.',
            $connection,
            $token->userId ?? '(unknown)',
            $token->expiresAt->format('j M Y, g:ia'),
        ));

        return $this->redirectToRoute('admin_item_index');
    }

    private function available(): MercadoLibreTokenProviderFactory
    {
        // Checked here rather than at Mercado Libre: without it, /meli/connect
        // cheerfully redirects to a consent URL with an empty client_id and the
        // seller lands on a broken login page with nothing to explain it.
        if (null === $this->meli || '' === $this->clientId
            || null === $this->connectionName || '' === $this->connectionName) {
            throw $this->createNotFoundException(
                'Mercado Libre is not configured. Set MELI_CLIENT_ID, MELI_CLIENT_SECRET and MELI_CONNECTION.',
            );
        }

        return $this->meli;
    }
}
