<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\CameraBundle\Contract\CaptureHandlerInterface;
use Survos\CameraBundle\Contract\CaptureRequest;
use Survos\CameraBundle\Contract\CaptureResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ItemCaptureHandler implements CaptureHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRepository $items,
    ) {
    }

    public function handle(CaptureRequest $request): CaptureResult
    {
        // Idempotent: the outbox retries a capture verbatim on a transient network failure,
        // using the same client-generated id. A second arrival must not create a second Item.
        $existing = $this->items->findOneByClientId($request->clientId);
        if (null !== $existing) {
            return new CaptureResult((string) $existing->getId(), (string) $existing->marking);
        }

        $item = new Item($request->clientId);

        // The spoken note arrives as text, not audio: the browser transcribes it
        // and we keep the string. It goes in before the flush so the kickoff
        // dispatched on postFlush hands the model a note it can actually read —
        // "the handle is chipped" is worth more than another angle of the mug.
        $transcript = $request->metadata['transcript'] ?? null;
        if (\is_string($transcript) && trim($transcript) !== '') {
            $item->setTranscript(trim($transcript));
        }

        foreach ($request->photos as $photo) {
            $item->addMedia($this->buildMedia(MediaKind::Photo, $photo));
        }

        if (null !== $request->audio) {
            $item->addMedia($this->buildMedia(MediaKind::Audio, $request->audio));
        }

        $this->em->persist($item);
        $this->em->flush();

        // No dispatch here on purpose. ItemFlow's initial place declares
        // next: [TRANSITION_SUGGEST], and the bundle's InitialPlaceKickoffListener
        // fires it on postFlush — after the row is committed, so a worker reading
        // the message can actually find the item. What happens after a capture is
        // a property of the graph, not of this handler.

        return new CaptureResult((string) $item->getId(), (string) $item->marking);
    }

    private function buildMedia(MediaKind $kind, UploadedFile $file): Media
    {
        $media = new Media($kind);
        $media->setFile($file);

        return $media;
    }
}
