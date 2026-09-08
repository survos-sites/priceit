<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Item;
use App\Repository\ItemRepository;
use App\Service\DepotPrintClient;
use App\Service\EbayListingPublisher;
use App\Service\PricingSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Survos\MarketplaceContracts\Exception\MarketplaceException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Review/pricing surface: browse captured items, edit AI-suggested title/description/price. */
#[Route('/admin', name: 'admin_')]
final class ItemController extends AbstractController
{
    #[Route('/', name: 'item_index')]
    public function index(ItemRepository $items, DepotPrintClient $depot): Response
    {
        return $this->render('admin/item/index.html.twig', [
            'items' => $items->findBy([], ['createdAt' => 'DESC']),
            'depotConfigured' => $depot->isConfigured(),
        ]);
    }

    #[Route('/items/{id}', name: 'item_show')]
    public function show(
        Item $item,
        PricingSuggestionService $pricing,
        DepotPrintClient $depot,
        EbayListingPublisher $ebay,
    ): Response {
        return $this->render('admin/item/show.html.twig', [
            'item' => $item,
            'aiConfigured' => $pricing->isConfigured(),
            'depotConfigured' => $depot->isConfigured(),
            'ebayConfigured' => $ebay->isAvailable(),
            // Shown next to a disabled button, so "why can't I click this" is
            // answered on the page rather than in the logs.
            'ebayBlockers' => $ebay->blockers($item),
        ]);
    }

    /**
     * Put the item on eBay.
     *
     * Synchronous, unlike the workflow's async `list` transition: someone pressed a
     * button and is looking at the screen, and "it might appear on eBay shortly" is
     * a poor answer for an action that publishes something publicly.
     */
    #[Route('/items/{id}/list-on-ebay', name: 'item_list_ebay', methods: ['POST'])]
    public function listOnEbay(Item $item, Request $request, EbayListingPublisher $ebay): Response
    {
        if (!$this->isCsrfTokenValid('item_list_ebay_'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $blockers = $ebay->blockers($item);
        if ([] !== $blockers) {
            $this->addFlash('danger', implode(' ', $blockers));

            return $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
        }

        try {
            $ebay->publish($item, $request->request->getString('categoryId') ?: null);
            $this->addFlash('success', sprintf('Listed on eBay: %s', $item->getEbayUrl() ?? $item->getEbayOfferId()));
        } catch (MarketplaceException $e) {
            // eBay's rejections name the field and the accepted values; passing the
            // message through beats "could not list".
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
    }

    /** Photo in, title/description/price out. */
    #[Route('/items/{id}/suggest', name: 'item_suggest', methods: ['POST'])]
    public function suggest(Item $item, Request $request, PricingSuggestionService $pricing): Response
    {
        if (!$this->isCsrfTokenValid('item_suggest_'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $pricing->suggest($item);
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
    }

    /** Build the ZPL and push it at depot's Zebra. */
    #[Route('/items/{id}/print', name: 'item_print', methods: ['POST'])]
    public function print(Item $item, Request $request, DepotPrintClient $depot): Response
    {
        if (!$this->isCsrfTokenValid('item_print_'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The QR points back at this item's page, so a label on a table is a
        // route to the record rather than just a price.
        $qr = $this->generateUrl('admin_item_show', ['id' => $item->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        $result = $depot->printLabel($item, $qr, max(1, (int) $request->request->get('copies', 1)));
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        // Printing a row from the list should leave you on the list, mid-way down a stack of
        // items you are working through, rather than on the detail page of the one you just
        // dealt with.
        return 'index' === $request->request->get('redirect')
            ? $this->redirectToRoute('admin_item_index')
            : $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
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
