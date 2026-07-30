<?php

declare(strict_types=1);

namespace Survos\CameraBundle\Controller;

use Survos\CameraBundle\Contract\CaptureHandlerInterface;
use Survos\CameraBundle\Contract\CaptureRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaptureController
{
    public function __construct(
        private readonly CaptureHandlerInterface $handler,
    ) {
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
        );

        $result = $this->handler->handle($captureRequest);

        return new JsonResponse($result->toArray(), Response::HTTP_CREATED);
    }
}
