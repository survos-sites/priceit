<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AssetController extends AbstractController
{
    /**
     * What a loan-closet QR code points at.
     *
     * Keyed by the asset number printed on the label rather than the database id, so the URL
     * contains the same string a volunteer reads aloud and the same string Quickbase stores.
     * Scanning, reading, and looking the item up in the closet all use one identifier.
     *
     * Short on purpose: it is encoded into a QR square roughly a centimetre across, and every
     * character costs modules that have to survive being printed at 203dpi and photographed
     * under a fluorescent tube.
     */
    #[Route('/e/{assetNumber}', name: 'app_asset_show', requirements: ['assetNumber' => '[A-Za-z0-9-]+'], methods: ['GET'])]
    public function show(string $assetNumber, ItemRepository $items): Response
    {
        $item = $items->findOneByAssetNumber($assetNumber)
            ?? throw $this->createNotFoundException(sprintf('No item carries the asset number "%s".', $assetNumber));

        return $this->redirectToRoute('admin_item_show', ['id' => $item->getId()]);
    }
}
