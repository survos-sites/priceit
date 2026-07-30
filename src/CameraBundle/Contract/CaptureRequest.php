<?php

declare(strict_types=1);

namespace Survos\CameraBundle\Contract;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * One capture session from the browser outbox: one or more photos, an optional audio note,
 * and whatever free-form metadata the client attached (captured_at, client timezone, etc).
 */
final class CaptureRequest
{
    /**
     * @param UploadedFile[] $photos
     */
    public function __construct(
        public readonly string $clientId,
        public readonly array $photos,
        public readonly ?UploadedFile $audio,
        public readonly array $metadata = [],
    ) {
    }
}
