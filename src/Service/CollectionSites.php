<?php

declare(strict_types=1);
namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

final class CollectionSites
{
    public const PROFILES = [
        'stone' => ['name' => 'Gravestones', 'description' => 'Walk the rows. Photograph each stone. Keep every detail together.', 'detail' => 'Continuous capture with required GPS. Link additional views to the previous stone without closing the camera.', 'note' => 'Local capture prototype: photos stay in this browser. Upload and AI transcription are not connected yet.'],
        'medical' => ['name' => 'Medical loan closet', 'description' => 'Turn equipment photos into a useful collection.', 'detail' => 'Photograph the item and its label, add notes, then review the identification before saving.', 'note' => 'Review suggestions before saving. Saving does not certify readiness to lend.'],
        'garage-sale' => ['name' => 'Garage sale', 'description' => 'Photograph your items. Find a starting price.', 'detail' => 'Capture photos and notes, then review suggested descriptions and local sale prices.', 'note' => 'AI suggestions are starting points for your review.'],
        'resale' => ['name' => 'Resale', 'description' => 'Capture the details that make an online listing useful.', 'detail' => 'Photograph an item, its markings and condition for an online resale suggestion.', 'note' => 'Review descriptions and prices before listing.'],
    ];

    public function all(Request $request): array
    {
        $local = str_ends_with($request->getHost(), '.wip') || in_array($request->getHost(), ['localhost', '127.0.0.1'], true);
        $sites = [];
        foreach (self::PROFILES as $profile => $info) {
            $path = '/sites/'.$profile;
            $sites[$profile] = $info + [
                'profile' => $profile,
                'siteUrl' => $local ? 'https://'.$profile.'.iit.wip/' : 'https://'.$profile.'--iit.survos.org/',
                'appUrl' => $path.'/app?embedded=1',
                'phoneUrl' => 'https://'.$profile.'--iit.survos.org/app',
            ];
        }
        return $sites;
    }
}
