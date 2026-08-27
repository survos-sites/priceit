<?php

declare(strict_types=1);

namespace App\Controller;

use App\Profile\CaptureProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

/** The mobile app: capture only. Review/pricing lives in Admin\ItemController under /admin. */
final class ItemController extends AbstractController
{
    public function __construct(
        // Absent in prod, where there is no profiler service at all.
        #[Autowire(service: 'profiler')]
        private readonly ?Profiler $profiler = null,
    ) {
    }

    #[Route('/capture', name: 'item_capture')]
    public function capture(Request $request): Response
    {
        // The landing page runs this inside a phone mockup. In dev the web
        // profiler injects its toolbar into any HTML response, which lands
        // across the bottom of the "phone" and reads as part of the app.
        if ($request->query->getBoolean('embedded')) {
            $this->profiler?->disable();
        }

        return $this->render('item/capture.html.twig', ['profiles' => CaptureProfile::cases()]);
    }
}
