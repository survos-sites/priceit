<?php

declare(strict_types=1);

namespace Survos\CameraBundle\Contract;

/**
 * What the host hands back after persisting a capture. Deliberately just an id + status string
 * -- the outbox only needs enough to mark the local record 'confirmed' and stop retrying.
 */
final class CaptureResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
    ) {
    }

    /** @return array{id: string, status: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status];
    }
}
