<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\Etsy\Auth\EtsyCredentials;
use Survos\Etsy\Auth\EtsyScope;
use Survos\Etsy\Auth\OAuthService;
use Survos\MarketplaceBundle\Token\EtsyTokenProviderFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connect an Etsy seller, once.
 *
 * The credentials come from the CONNECTION, not from shared config. Etsy's
 * instantly-approved Seller App tier only reaches the shop that registered it, so
 * each seller registers their own app and hands over a keystring. Dave's app,
 * Dave's shop, Dave's keys — he never gives a password and can revoke it himself.
 *
 * PKCE is mandatory on Etsy, so the verifier minted at /connect has to survive to
 * the callback. It lives in the session: single use, short lived, and losing it
 * only costs a restart of the consent.
 */
#[Route('/etsy', name: 'etsy_')]
final class EtsyOAuthController extends AbstractController
{
    /** @param array<string, array{driver: string, site: string, options?: array<string, mixed>}> $connections */
    public function __construct(
        #[Autowire('%survos_marketplace.connections%')]
        private readonly array $connections = [],
        private readonly ?EtsyTokenProviderFactory $etsy = null,
    ) {
    }

    #[Route('/connect/{connection}', name: 'connect')]
    public function connect(Request $request, string $connection): Response
    {
        [$factory, $credentials] = $this->resolve($connection);

        $state = bin2hex(random_bytes(16));
        $verifier = OAuthService::generateCodeVerifier();

        $session = $request->getSession();
        $session->set('etsy_oauth_state', $state);
        $session->set('etsy_oauth_verifier', $verifier);
        $session->set('etsy_oauth_connection', $connection);

        return $this->redirect(
            $factory->oauth($credentials)->consentUrl(EtsyScope::forListing(), $state, $verifier),
        );
    }

    #[Route('/callback', name: 'callback')]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();

        if ('' !== $error = $request->query->getString('error')) {
            $this->addFlash('danger', sprintf(
                'Etsy declined: %s. %s',
                $error,
                $request->query->getString('error_description'),
            ));

            return $this->redirectToRoute('admin_item_index');
        }

        $expectedState = $session->get('etsy_oauth_state');
        $verifier = $session->get('etsy_oauth_verifier');
        $connection = $session->get('etsy_oauth_connection');
        foreach (['etsy_oauth_state', 'etsy_oauth_verifier', 'etsy_oauth_connection'] as $key) {
            $session->remove($key);
        }

        if (!\is_string($expectedState) || !hash_equals($expectedState, $request->query->getString('state'))) {
            throw $this->createAccessDeniedException('Etsy OAuth state mismatch.');
        }

        if (!\is_string($verifier) || '' === $verifier) {
            // The session went away between consent and callback. Etsy rejects the
            // exchange without the verifier, so say that rather than guessing.
            $this->addFlash('danger', 'The Etsy PKCE verifier was lost with the session. Start again at /etsy/connect.');

            return $this->redirectToRoute('admin_item_index');
        }

        if ('' === $code = $request->query->getString('code')) {
            $this->addFlash('danger', 'Etsy sent no authorization code.');

            return $this->redirectToRoute('admin_item_index');
        }

        [$factory, $credentials] = $this->resolve((string) $connection);
        $token = $factory->oauth($credentials)->exchangeCode($code, $verifier);

        $factory->store()->save((string) $connection, EtsyTokenProviderFactory::toNeutral($token));

        $this->addFlash('success', sprintf(
            'Etsy connected for "%s" as seller %s. Access token expires %s; refresh good for ~90 days.',
            $connection,
            $token->userId ?? '(unknown)',
            $token->expiresAt->format('j M Y, g:ia'),
        ));

        return $this->redirectToRoute('admin_item_index');
    }

    /** @return array{EtsyTokenProviderFactory, EtsyCredentials} */
    private function resolve(string $connection): array
    {
        $config = $this->connections[$connection] ?? null;

        if (null === $this->etsy || null === $config || 'etsy' !== ($config['driver'] ?? null)) {
            throw $this->createNotFoundException(sprintf('No Etsy connection named "%s".', $connection));
        }

        /** @var array<string, mixed> $options */
        $options = $config['options'] ?? [];
        $keystring = (string) ($options['keystring'] ?? '');

        if ('' === $keystring) {
            throw $this->createNotFoundException(sprintf(
                'Etsy connection "%s" has no keystring. The seller registers a Seller App at '
                . 'etsy.com/developers/register-seller-app and supplies it.',
                $connection,
            ));
        }

        return [$this->etsy, new EtsyCredentials(
            keystring: $keystring,
            sharedSecret: isset($options['shared_secret']) ? (string) $options['shared_secret'] : null,
            redirectUri: isset($options['redirect_uri']) ? (string) $options['redirect_uri'] : null,
        )];
    }
}
