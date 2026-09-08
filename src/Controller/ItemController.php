<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Repository\ItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The mobile app. Capture, and reviewing what was captured.
 *
 * Review used to live only in Admin\ItemController under /admin, on the Tabler skin --
 * so the one thing you do between shooting an item and listing it happened outside the
 * app, in a desk UI, on a phone. These routes are the same data in the F7 shell, reachable
 * from the tabbar. The mutating actions (price, print, list on a marketplace) stay in
 * /admin for now; this is the review surface, not a second copy of the admin.
 */
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

    #[Route('/items', name: 'app_item_index')]
    public function index(ItemRepository $items): Response
    {
        return $this->render('item/index.html.twig', [
            // Same ordering as the admin list: newest first is what you want on a phone
            // straight after capturing, and it is the order the operator already expects.
            'items' => $items->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/items/{id}', name: 'app_item_show', requirements: ['id' => '\\d+'])]
    public function show(Item $item): Response
    {
        return $this->render('item/show.html.twig', ['item' => $item]);
    }
}
