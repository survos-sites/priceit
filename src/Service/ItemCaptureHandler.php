<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Message\SuggestItemPricing;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\CameraBundle\Contract\CaptureHandlerInterface;
use Survos\CameraBundle\Contract\CaptureRequest;
use Survos\CameraBundle\Contract\CaptureResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class ItemCaptureHandler implements CaptureHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRepository $items,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function handle(CaptureRequest $request): CaptureResult
    {
        // Idempotent: the outbox retries a capture verbatim on a transient network failure,
        // using the same client-generated id. A second arrival must not create a second Item.
        $existing = $this->items->findOneByClientId($request->clientId);
        if (null !== $existing) {
            return new CaptureResult((string) $existing->getId(), $existing->getStatus()->value);
        }

        $item = new Item($request->clientId);

        foreach ($request->photos as $photo) {
            $item->addMedia($this->buildMedia(MediaKind::Photo, $photo));
        }

        if (null !== $request->audio) {
            $item->addMedia($this->buildMedia(MediaKind::Audio, $request->audio));
        }

        $this->em->persist($item);
        $this->em->flush();

        // Dispatched after the flush, so the row exists and Vich has written the
        // files before a worker in another process goes looking for them.
        //
        // Async on purpose: the phone is waiting on this response, often on a bad
        // connection, and a vision call takes seconds. The upload returns as soon
        // as the bytes are safe; the suggestion catches up.
        $this->bus->dispatch(new SuggestItemPricing((int) $item->getId()));

        return new CaptureResult((string) $item->getId(), $item->getStatus()->value);
    }

    private function buildMedia(MediaKind $kind, UploadedFile $file): Media
    {
        $media = new Media($kind);
        $media->setFile($file);

        return $media;
    }
}
