<?php

declare(strict_types=1);

namespace Survos\CameraBundle\Contract;

/**
 * The one seam between camera-bundle and its host. The bundle owns capture, queueing, and
 * upload; the host owns what a capture *means* -- turning photos+audio+metadata into whatever
 * domain entity it has (an Item, a Submission, an Observation...). See survos/mono#24.
 */
interface CaptureHandlerInterface
{
    public function handle(CaptureRequest $request): CaptureResult;
}
