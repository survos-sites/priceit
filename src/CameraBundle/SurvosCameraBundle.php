<?php

declare(strict_types=1);

namespace Survos\CameraBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Offline-first photo(+audio)-capture capability, incubating here in its own namespace before
 * a second consuming app promotes it to mono/bu/camera-bundle (see survos/mono#24). Everything
 * under this directory should stay ignorant of App\Entity\Item/Media -- the host wires itself
 * up via Contract\CaptureHandlerInterface, same boundary #24 draws with EventProviderInterface.
 */
final class SurvosCameraBundle extends Bundle
{
}
