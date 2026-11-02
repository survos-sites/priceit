<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InventoryCollection;
use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Repository\ItemRepository;
use App\Service\ItemCaptureHandler;
use App\Workflow\ItemFlow;
use Doctrine\ORM\EntityManagerInterface;
use Survos\CameraBundle\Contract\CaptureRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[IsGranted('ROLE_USER')]
#[Route('/inventory', name: 'inventory_')]
final class InventoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ItemRepository $items): Response
    {
        return $this->render('inventory/index.html.twig', [
            'collections' => $this->em->getRepository(InventoryCollection::class)->findBy([], ['name' => 'ASC']),
            'items' => $items->findBy(['profile' => CaptureProfile::MedicalEquipment], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/capture', name: 'capture', methods: ['GET'])]
    public function capture(): Response
    {
        return $this->render('item/capture.html.twig', ['inventory' => true, 'profiles' => [CaptureProfile::MedicalEquipment]]);
    }

    #[Route('/capture', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, ItemCaptureHandler $handler): JsonResponse
    {
        if (!$this->isCsrfTokenValid('inventory_capture', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Session expired. Reload the capture page and retry the upload.'], 403);
        }
        $clientId = $request->request->getString('client_id');
        $photos = $request->files->all('photos');
        $audio = $request->files->all('audio_notes');
        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $clientId) || count($photos) < 1 || count($photos) > 3 || count($audio) > 10) {
            return $this->json(['error' => 'Use one to three photos and at most ten voice notes per item.'], 422);
        }
        foreach (array_merge($photos, $audio) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 12 * 1024 * 1024) {
                return $this->json(['error' => 'Each photo or recording must be a valid file under 12 MB.'], 422);
            }
        }
        foreach ($photos as $file) {
            if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return $this->json(['error' => 'Use JPEG, PNG or WebP photos.'], 422);
            }
        }
        foreach ($audio as $file) {
            if (!in_array($file->getMimeType(), ['audio/webm', 'video/webm', 'audio/ogg', 'video/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/mpeg', 'audio/wav', 'audio/x-wav'], true)) {
                return $this->json(['error' => 'Unsupported voice recording format.'], 422);
            }
        }
        $metadata = json_decode($request->request->getString('metadata', '{}'), true);
        if (!is_array($metadata) || !is_string($metadata['transcript'] ?? '')) {
            return $this->json(['error' => 'Invalid capture notes.'], 422);
        }
        $result = $handler->handle(new CaptureRequest(
            clientId: $clientId,
            photos: $photos,
            audio: null,
            metadata: ['inventory' => true, 'profile' => 'medical', 'transcript' => mb_substr($metadata['transcript'] ?? '', 0, 12000)],
            audioNotes: $audio,
        ));

        return $this->json($result->toArray() + ['reviewUrl' => $this->generateUrl('inventory_review', ['id' => $result->id])], 201);
    }

    #[Route('/items/{id}', name: 'review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(Item $item): Response
    {
        $this->requireInventory($item);

        return $this->render('inventory/review.html.twig', [
            'item' => $item,
            'collections' => $this->em->getRepository(InventoryCollection::class)->findBy([], ['name' => 'ASC']),
            'pending' => $item->marking === ItemFlow::PLACE_NEW && $item->getInventorySavedAt() === null,
        ]);
    }

    #[Route('/items/{id}/status', name: 'status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(Item $item): JsonResponse
    {
        $this->requireInventory($item);
        $response = $this->json(['pending' => $item->marking === ItemFlow::PLACE_NEW && $item->getInventorySavedAt() === null]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    #[Route('/items/{id}/retry', name: 'retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(Item $item, Request $request, #[Target('item')] WorkflowInterface $workflow): Response
    {
        $this->requireInventory($item);
        $this->denyUnlessToken($request, $item);
        if ($item->getInventorySavedAt() === null && $item->marking === ItemFlow::PLACE_FAILED && $workflow->can($item, ItemFlow::TRANSITION_SUGGEST)) {
            $workflow->apply($item, ItemFlow::TRANSITION_SUGGEST);
            $this->em->flush();
        }
        return $this->redirectToRoute('inventory_review', ['id' => $item->getId()], 303);
    }

    #[Route('/items/{id}/save', name: 'save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Item $item, Request $request): Response
    {
        $this->requireInventory($item);
        $this->denyUnlessToken($request, $item);
        // A repeated POST returns the existing saved record, without duplicating or overwriting it.
        if ($item->getInventorySavedAt() !== null) {
            return $this->redirectToRoute('inventory_review', ['id' => $item->getId()], 303);
        }
        $title = trim($request->request->getString('title'));
        $description = trim($request->request->getString('description'));
        $equipment = trim($request->request->getString('equipmentType'));
        $location = trim($request->request->getString('location'));
        if ($title === '' || mb_strlen($title) > 255 || mb_strlen($equipment) > 150 || mb_strlen($location) > 255 || mb_strlen($description) > 12000) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Check the required title and field lengths before saving.');
        }
        $choice = $request->request->getString('collection');
        $collections = $this->em->getRepository(InventoryCollection::class);
        if ($choice === 'medical') {
            $collection = $collections->findOneBy(['name' => 'Medical loan closet']);
            if ($collection === null) {
                $collection = new InventoryCollection('Medical loan closet');
                $this->em->persist($collection);
            }
        } else {
            $collection = ctype_digit($choice) ? $collections->find((int) $choice) : null;
            if ($collection === null) {
                throw $this->createNotFoundException('Collection not found.');
            }
        }
        $item->setTitle($title)->setDescription($description)->setEquipmentType($equipment);
        $item->setCategory($item->getInventorySuggestion()['category'] ?? null);
        $item->setStorageLocation($location !== '' ? $location : null);
        $item->saveToCollection($collection);
        $this->em->flush();

        return $this->redirectToRoute('inventory_review', ['id' => $item->getId()], 303);
    }

    private function requireInventory(Item $item): void
    {
        if (!$item->isInventoryIntake()) {
            throw $this->createNotFoundException();
        }
    }

    private function denyUnlessToken(Request $request, Item $item): void
    {
        if (!$this->isCsrfTokenValid('inventory_'.$item->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Reload the page before saving.');
        }
    }
}
