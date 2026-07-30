<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Item;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Review/pricing surface: browse captured items, edit AI-suggested title/description/price. */
#[Route('/admin', name: 'admin_')]
final class ItemController extends AbstractController
{
    #[Route('/', name: 'item_index')]
    public function index(ItemRepository $items): Response
    {
        return $this->render('admin/item/index.html.twig', [
            'items' => $items->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/items/{id}', name: 'item_show')]
    public function show(Item $item): Response
    {
        return $this->render('admin/item/show.html.twig', ['item' => $item]);
    }

    #[Route('/items/{id}/edit', name: 'item_edit', methods: ['POST'])]
    public function edit(Item $item, Request $request, EntityManagerInterface $em): Response
    {
        $item->setTitle($request->request->get('title'));
        $item->setDescription($request->request->get('description'));
        $price = $request->request->get('price');
        $item->setPrice('' !== $price ? $price : null);
        $em->flush();

        return $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
    }
}
