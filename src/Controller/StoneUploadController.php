<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\StoneCaptureUpload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StoneUploadController extends AbstractController
{
    #[Route('/stones/upload', name: 'stone_upload', methods: ['POST'])]
    public function upload(Request $request, StoneCaptureUpload $uploader): JsonResponse
    {
        if (!$this->isCsrfTokenValid('stone_upload', $request->headers->get('X-CSRF-Token'))) return $this->json(['error' => 'Reload the page to renew your upload session. Photos remain saved locally.'], 403);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) return $this->json(['error' => 'Photo is required.'], 422);
        try {
            return $this->json($uploader->upload($file, $request->request->getString('metadata'), (string) $this->getUser()->getId()));
        } catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 422); }
        catch (\DomainException $e) { return $this->json(['error' => $e->getMessage()], 409); }
        catch (\RuntimeException|\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            return $this->json(['error' => 'SSAI upload unavailable or unconfirmed. Photos remain saved locally; retry upload.'], 503);
        }
    }
}
