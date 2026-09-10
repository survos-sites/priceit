<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PricingSuggestionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Photos in, "what is it and what do we charge" out. Nothing is saved.
 *
 * Public on purpose. Volunteers were already doing this by hand in ChatGPT or Google Lens, and a
 * login screen in front of a quick lookup sends them straight back there. The cost of leaving it
 * open is API spend from anyone who has the link, which is acceptable for the length of a sale.
 */
final class LookupController extends AbstractController
{
    private const MAX_PHOTOS = 3;
    private const MAX_BYTES = 12 * 1024 * 1024;

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
        foreach ((array) $request->files->get('photos', []) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > self::MAX_BYTES) {
                continue;
            }
            $photos[] = ['bytes' => (string) file_get_contents($file->getPathname()), 'mime' => (string) $file->getMimeType()];
            if (\count($photos) >= self::MAX_PHOTOS) {
                break;
            }
        }

        if ($photos === []) {
            return new JsonResponse(['ok' => false, 'message' => 'Take or choose at least one photo.'], 400);
        }

        $started = microtime(true);
        $answer = $pricing->lookup($photos, $request->request->getString('note'));
        $answer['seconds'] = round(microtime(true) - $started, 1);

        return new JsonResponse($answer, $answer['ok'] ? 200 : 502);
    }
}
