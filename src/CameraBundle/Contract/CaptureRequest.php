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
        /**
         * Photos already PUT straight to S3, as public URLs.
         *
         * The bytes never reach us in this path, so there is nothing to store —
         * only a reference to record. $photos stays for clients that cannot
         * presign (no credentials configured), and the two are additive rather
         * than exclusive: a retry that half-succeeded can send some of each.
         *
         * @var list<string>
         */
        public readonly array $photoUrls = [],
    ) {
    }
}
