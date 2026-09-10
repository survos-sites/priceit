<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The shop window: what the app is, with the actual app running inside a phone
 * frame beside it, and a QR that opens it on a real handset.
 *
 * The QR has to point at the public tunnel hostname rather than at whatever
 * this page was loaded from — a phone cannot reach 127.0.0.1:8013 or price.wip,
 * and getUserMedia needs a secure context, so a LAN IP over plain http is no
 * use either.
 */
final class LandingController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    #[Route('/', name: 'app_landing')]
    public function index(Request $request): Response
    {
        $captureUrl = $this->generateUrl('item_capture');
        // Same page, but asks the profiler to keep its toolbar out of the mockup.
        $embeddedUrl = $this->generateUrl('item_capture', ['embedded' => 1]);

        // Fall back to this host so the page is still usable (and the QR still
        // scannable from the same machine) before the tunnel is configured.
        $base = $this->publicUrl !== ''
            ? rtrim($this->publicUrl, '/')
            : rtrim($request->getSchemeAndHttpHost(), '/');

        return $this->render('landing.html.twig', [
            'captureUrl' => $captureUrl,
            'embeddedUrl' => $embeddedUrl,
            // The QR is what a pricer scans at the table, so it opens the quick lookup.
            'phoneUrl' => $base.$this->generateUrl('app_lookup'),
            'adminUrl' => $this->generateUrl('admin_item_index'),
            'tunnelConfigured' => $this->publicUrl !== '',
            'absoluteCaptureUrl' => $this->generateUrl('item_capture', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
