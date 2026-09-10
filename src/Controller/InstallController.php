<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One link to hand a volunteer: it installs PriceIt as an app.
 *
 * Capture has to run installed, not in a browser tab. In Chrome, every tab showing the capture
 * page competes for the one camera, and a volunteer switching between the app and the browser
 * ends up with a tab holding the camera open in the background. An installed PWA gets a window
 * of its own.
 *
 * Public, because the person installing may not have an account yet; the app asks them to sign
 * in once it opens.
 */
final class InstallController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    #[Route('/install', name: 'app_install')]
    public function index(Request $request): Response
    {
        $base = $this->publicUrl !== ''
            ? rtrim($this->publicUrl, '/')
            : rtrim($request->getSchemeAndHttpHost(), '/');

        return $this->render('install.html.twig', [
            'installUrl' => $base.$this->generateUrl('app_install'),
            'captureUrl' => $this->generateUrl('item_capture'),
        ]);
    }
}
