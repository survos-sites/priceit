<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The mobile app: capture only. Review/pricing lives in Admin\ItemController under /admin. */
final class ItemController extends AbstractController
{
    #[Route('/', name: 'item_capture')]
    public function capture(): Response
    {
        return $this->render('item/capture.html.twig');
    }
}
