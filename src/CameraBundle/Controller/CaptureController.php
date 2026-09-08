<?php

declare(strict_types=1);

namespace Survos\CameraBundle\Controller;

use Survos\CameraBundle\Contract\CaptureHandlerInterface;
use Survos\CameraBundle\Contract\CaptureRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Service\PresignedUploadService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaptureController
{
    public function __construct(
        private readonly CaptureHandlerInterface $handler,
        private readonly ?PresignedUploadService $uploads = null,
    ) {
    }

    /**
     * Ask for URLs the phone can PUT photos to directly.
     *
     * Answers 503 when S3 is not configured, which is the dev default. The client
     * treats that as "post the bytes the old way" rather than an error — a laptop
     * with no credentials must still be able to capture.
     */
    #[Route('/api/camera/upload-urls', name: 'camera_bundle_upload_urls', methods: ['POST'])]
    public function uploadUrls(Request $request): Response
    {
        if (null === $this->uploads || !$this->uploads->isAvailable()) {
            return new JsonResponse(
                ['error' => 's3_unavailable', 'fallback' => 'multipart'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $payload = json_decode((string) $request->getContent(), true);
        $clientId = is_array($payload) ? (string) ($payload['client_id'] ?? '') : '';

        if ('' === $clientId) {
            return new JsonResponse(['error' => 'client_id is required'], Response::HTTP_BAD_REQUEST);
        }

        $types = is_array($payload) && is_array($payload['content_types'] ?? null)
            ? array_values($payload['content_types'])
            : ['image/jpeg'];

        $grants = [];
        foreach ($types as $index => $contentType) {
            $grants[] = $this->uploads->presign($clientId, $index, (string) $contentType);
        }

        return new JsonResponse(['uploads' => $grants]);
    }

    #[Route('/api/camera/capture', name: 'camera_bundle_capture', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $clientId = (string) $request->request->get('client_id');
        if ('' === $clientId) {
            return new JsonResponse(['error' => 'client_id is required'], Response::HTTP_BAD_REQUEST);
        }

        $metadata = json_decode((string) $request->request->get('metadata', '{}'), true, flags: JSON_THROW_ON_ERROR);

        $captureRequest = new CaptureRequest(
            clientId: $clientId,
            photos: $request->files->all('photos'),
            audio: $request->files->get('audio'),
            metadata: \is_array($metadata) ? $metadata : [],
            photoUrls: array_values(array_filter(
                $request->request->all('photo_urls'),
                static fn (mixed $u): bool => \is_string($u) && str_starts_with($u, 'https://'),
            )),
        );

        $result = $this->handler->handle($captureRequest);

        return new JsonResponse($result->toArray(), Response::HTTP_CREATED);
    }
}
