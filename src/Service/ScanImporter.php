<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Profile\CaptureProfile;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pulls scanned items out of ssai and into priceit for pricing and listing.
 *
 * Reads the API rather than a directory, because the hard part of a directory is
 * knowing which files are one item — and ssai already knows. Its intake profiles
 * declare it (`postcard_posted_front_back`, `photo_front_only`), which is how one
 * intake turns 14 images into 7 items. Re-deriving that downstream from filenames
 * would be guessing at something already recorded.
 *
 * Images are REFERENCED, never copied. A scan is already in S3 behind imgproxy
 * with a durable public URL; pulling the bytes into priceit's bucket would
 * duplicate storage and add a failure mode, and marketplaces fetch by URL anyway.
 *
 * Idempotent on the remote id: re-running updates rather than duplicating, so a
 * half-finished import can simply be run again.
 */
final readonly class ScanImporter
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private ItemRepository $items,
        #[Autowire('%env(default::SCANSTATION_BASE_URL)%')]
        private ?string $baseUrl = null,
    ) {
    }

    #[AsCommand('priceit:import-scans', 'Import scanned items from a scanstation (ssai) API')]
    public function importCommand(
        SymfonyStyle $io,
        #[Argument('Tenant id, e.g. dave')] string $tenantId,
        #[Option('Only this intake')] ?string $intake = null,
        #[Option('Only items in this workflow state')] ?string $marking = null,
        // NOT --profile: Symfony's console already owns that for the profiler.
        #[Option('Capture profile for imported items: garage_sale, resale, medical')] string $captureProfileCode = 'resale',
        #[Option('Maximum items to import')] int $limit = 50,
        #[Option('Show what would happen, change nothing')] bool $dryRun = false,
        #[Option('Base URL, overriding SCANSTATION_BASE_URL')] ?string $base = null,
    ): int {
        $base = rtrim($base ?? (string) $this->baseUrl, '/');

        if ('' === $base) {
            $io->error('No scanstation base URL. Set SCANSTATION_BASE_URL or pass --base.');

            return Command::FAILURE;
        }

        $captureProfile = CaptureProfile::tryFrom($captureProfileCode);
        if (null === $captureProfile) {
            $io->error(sprintf('Unknown capture profile "%s".', $captureProfileCode));

            return Command::FAILURE;
        }

        // tenantId as a query parameter, not a subdomain: ssai already filters on it
        // and a per-tenant host would need DNS and routing for no gain here.
        $query = array_filter([
            'tenantId' => $tenantId,
            'intake' => $intake,
            'marking' => $marking,
            'itemsPerPage' => $limit,
        ], static fn (mixed $v): bool => null !== $v && '' !== $v);

        $io->comment(sprintf('GET %s/api/items.json?%s', $base, http_build_query($query)));

        try {
            $payload = $this->httpClient
                ->request('GET', $base . '/api/items.json', ['query' => $query, 'timeout' => 30])
                ->toArray();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // API Platform answers with a hydra collection or a plain list depending on
        // the format negotiated; both shapes turn up in practice.
        $remoteItems = $payload['hydra:member'] ?? $payload['member'] ?? $payload;
        if (!is_array($remoteItems)) {
            $io->error('Unexpected response shape.');

            return Command::FAILURE;
        }

        $created = $updated = $skipped = 0;
        $rows = [];

        foreach ($remoteItems as $remote) {
            if (!is_array($remote)) {
                continue;
            }

            $remoteId = (string) ($remote['id'] ?? $remote['@id'] ?? '');
            if ('' === $remoteId) {
                ++$skipped;

                continue;
            }

            $images = $this->imageUrlsFor($remote, $base);
            if ([] === $images) {
                // An item with no reachable image cannot be listed, and importing it
                // would just fill the review screen with things that can never go out.
                ++$skipped;
                $rows[] = [$remoteId, $remote['title'] ?? '—', 0, 'skipped: no images'];

                continue;
            }

            $clientId = 'ssai:' . $tenantId . ':' . $remoteId;
            $item = $this->items->findOneByClientId($clientId);
            $isNew = null === $item;

            if ($dryRun) {
                $rows[] = [$remoteId, $remote['title'] ?? '—', count($images), $isNew ? 'would create' : 'would update'];
                $isNew ? ++$created : ++$updated;

                continue;
            }

            $item ??= new Item($clientId, $captureProfile);
            $item->setTitle($this->titleFor($remote));

            $existing = [];
            foreach ($item->getMedia() as $media) {
                if ($media->isExternal()) {
                    $existing[(string) $media->getSourceUrl()] = true;
                }
            }

            foreach ($images as $url) {
                if (isset($existing[$url])) {
                    continue;
                }

                $media = new Media(MediaKind::Photo);
                $media->setSourceUrl($url);
                $media->setSourceRef($clientId);
                $item->addMedia($media);
                $this->em->persist($media);
            }

            $this->em->persist($item);
            $rows[] = [$remoteId, $item->getTitle() ?? '—', count($images), $isNew ? 'created' : 'updated'];
            $isNew ? ++$created : ++$updated;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['remote id', 'title', 'images', ''], $rows);
        $io->success(sprintf(
            '%s: %d created, %d updated, %d skipped.',
            $dryRun ? 'Dry run' : 'Imported',
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * Public image URLs for one remote item.
     *
     * ssai's Image carries renditionUrl/previewUrl from imgproxy over S3, so these
     * are durable and fetchable by a marketplace. Relative values are resolved
     * against the API base rather than dropped.
     *
     * @param array<string, mixed> $remote
     *
     * @return list<string>
     */
    private function imageUrlsFor(array $remote, string $base): array
    {
        $urls = [];

        foreach ((array) ($remote['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }

            foreach (['url', 'previewUrl', 'renditionUrl', 'contentUrl'] as $key) {
                $candidate = $image[$key] ?? null;
                if (!is_string($candidate) || '' === $candidate) {
                    continue;
                }

                $url = str_starts_with($candidate, 'http')
                    ? $candidate
                    : $base . '/' . ltrim($candidate, '/');

                if (str_starts_with($url, 'https://')) {
                    $urls[] = $url;

                    break;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** @param array<string, mixed> $remote */
    private function titleFor(array $remote): ?string
    {
        foreach (['title', 'name', 'code'] as $key) {
            $value = $remote[$key] ?? null;
            if (is_string($value) && '' !== $value) {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }
}
