<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Profile\CaptureProfile;
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

    private static function profileFrom(mixed $value): CaptureProfile
    {
        return \is_string($value)
            ? (CaptureProfile::tryFrom($value) ?? CaptureProfile::GarageSale)
            : CaptureProfile::GarageSale;
    }

    public function handle(CaptureRequest $request): CaptureResult
    {
        // Idempotent: the outbox retries a capture verbatim on a transient network failure,
        // using the same client-generated id. A second arrival must not create a second Item.
        $existing = $this->items->findOneByClientId($request->clientId);
        if (null !== $existing) {
            return new CaptureResult((string) $existing->getId(), (string) $existing->marking);
        }

        // The profile is chosen on the capture screen and decides everything downstream: what
        // the model is asked for, what the label carries, and where the item ends up. An
        // unrecognized value falls back to the auction profile rather than failing the upload
        // -- the phone is often on a bad connection and the photos matter more.
        $item = new Item($request->clientId, self::profileFrom($request->metadata['profile'] ?? null));

        // The spoken note arrives as text, not audio: the browser transcribes it
        // and we keep the string. It goes in before the flush so the kickoff
        // dispatched on postFlush hands the model a note it can actually read —
        // "the handle is chipped" is worth more than another angle of the mug.
        // Ticked on the capture screen. Set before the flush, because the
        // kickoff dispatched on postFlush is what eventually reads it.
        $item->printRequested = (bool) ($request->metadata['print'] ?? false);

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
