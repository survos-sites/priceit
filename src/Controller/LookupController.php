<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Profile\CaptureProfile;
use App\Service\PricingSuggestionService;
use App\Workflow\ItemFlow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Photos in, "what is it and what do we charge" out, and the item kept for next year.
 *
 * Public on purpose. Volunteers were already doing this by hand in ChatGPT or Google Lens, and a
 * login screen in front of a quick lookup sends them straight back there. The cost of leaving it
 * open is API spend from anyone who has the link, which is acceptable for the length of a sale.
 */
final class LookupController extends AbstractController
{
    private const MAX_PHOTOS = 3;
    private const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/lookup', name: 'app_lookup', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('lookup.html.twig');
    }

    #[Route('/lookup', name: 'app_lookup_ask', methods: ['POST'])]
    public function ask(Request $request, PricingSuggestionService $pricing): JsonResponse
    {
        // The model call runs while the phone waits. PHP's default 30s is too short for three
        // photos with thinking; the proxies in front give up at 60, so stay under that.
        set_time_limit(58);

        $photos = [];
        $files = [];
        foreach ((array) $request->files->get('photos', []) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > self::MAX_BYTES) {
                continue;
            }
            $photos[] = ['bytes' => (string) file_get_contents($file->getPathname()), 'mime' => (string) $file->getMimeType()];
            $files[] = $file;
            if (\count($photos) >= self::MAX_PHOTOS) {
                break;
            }
        }

        if ($photos === []) {
            return new JsonResponse(['ok' => false, 'message' => 'Take or choose at least one photo.'], 400);
        }

        $note = $request->request->getString('note');
        $started = microtime(true);
        $answer = $pricing->lookup($photos, $note);
        $answer['seconds'] = round(microtime(true) - $started, 1);

        if ($answer['ok']) {
            $answer['itemId'] = $this->archive($files, $answer['data'], $note);
        }

        return new JsonResponse($answer, $answer['ok'] ? 200 : 502);
    }

    /**
     * Every lookup is kept as an item: the photo and what the AI said, so next year's sale can
     * be priced against this one.
     *
     * The item is created already in `suggested`. The workflow's automatic first step
     * (suggest) fires only for items created in `new`, and running it here would pay for a
     * second AI call and, with print-now ticked, print a label nobody asked for.
     *
     * A failure here is logged and swallowed. The volunteer came for the answer, and losing
     * the archive copy of one item is better than losing the answer.
     *
     * @param list<UploadedFile>   $files
     * @param array<string, mixed> $data
     */
    private function archive(array $files, array $data, string $note): ?int
    {
        try {
            $item = new Item('lookup-'.bin2hex(random_bytes(8)), CaptureProfile::GarageSale);
            $item->marking = ItemFlow::PLACE_SUGGESTED;
            $item->setTitle($data['title'] ?? null);
            $item->setDescription($data['description'] ?? null);
            $item->setCategory($data['category'] ?? null);
            $item->setEquipmentType($data['equipmentType'] ?? null);
            if (isset($data['priceUsd'])) {
                $item->setPrice(number_format((float) $data['priceUsd'], 2, '.', ''));
            }
            if (trim($note) !== '') {
                $item->setTranscript(trim($note));
            }

            $confidence = ['high' => 0.8, 'medium' => 0.5, 'low' => 0.2][$data['confidence'] ?? ''] ?? 0.5;
            $estimates = [];
            if (($data['onlineLowUsd'] ?? 0) > 0 && ($data['onlineHighUsd'] ?? 0) > 0) {
                $estimates['resale'] = [
                    'low' => number_format((float) $data['onlineLowUsd'], 2, '.', ''),
                    'high' => number_format((float) $data['onlineHighUsd'], 2, '.', ''),
                    'currency' => 'USD',
                    'confidence' => $confidence,
                    'basis' => $data['why'] ?? null,
                ];
            }
            $item->setValueEstimates($estimates);

            $attributes = ['source' => 'lookup'];
            if (($data['tagPriceUsd'] ?? 0) > 0) {
                $attributes['tagPrice'] = number_format((float) $data['tagPriceUsd'], 2, '.', '');
            }
            $item->setAttributes($attributes);

            foreach ($files as $file) {
                $media = new Media(MediaKind::Photo);
                $media->setFile($file);
                $item->addMedia($media);
                $this->em->persist($media);
            }

            $this->em->persist($item);
            $this->em->flush();

            return $item->getId();
        } catch (\Throwable $e) {
            $this->logger->error('priceit: could not archive a lookup', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
