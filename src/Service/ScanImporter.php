<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Media;
use App\Entity\MediaKind;
use App\Profile\CaptureProfile;
use App\Repository\ItemRepository;
use App\Service\ClaimMapper;
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
        private ClaimMapper $claims,
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
        #[Option('Asking price for imported items, in USD. A placeholder for Dave to revise — ssai does not estimate value yet, and priceit deliberately does not run its own AI over images ssai has already read.')] string $price = '9.99',
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

        // tenantId is a query parameter, not a subdomain — ssai filters on it and a
        // per-tenant host would need DNS for no gain. It is optional because an
        // intake code already implies its tenant, and passing both can contradict.
        $query = array_filter([
            'tenantId' => 'all' === $tenantId ? null : $tenantId,
            'intake' => $intake,
            'marking' => $marking,
            'itemsPerPage' => $limit,
        ], static fn (mixed $v): bool => null !== $v && '' !== $v);

        $io->comment(sprintf('GET %s/api/items?%s', $base, http_build_query($query)));

        try {
            // No .json suffix: API Platform serves this by content negotiation and
            // 404s the extension.
            $payload = $this->httpClient
                ->request('GET', $base . '/api/items', [
                    'query' => $query,
                    'headers' => ['Accept' => 'application/ld+json'],
                    'timeout' => 30,
                ])
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

            // Not yet uploaded means not yet listable, so leave it in ssai rather
            // than importing something that can only sit in the review screen.
            if (!$this->isUploaded($remote)) {
                ++$skipped;
                $rows[] = [$remoteId, $this->titleFor($remote) ?? '—', 0, 'skipped: images not on S3 yet'];

                continue;
            }

            $images = $this->imageUrlsFor($remote, $base);
            if ([] === $images) {
                ++$skipped;
                $rows[] = [$remoteId, $this->titleFor($remote) ?? '—', 0, 'skipped: no usable image URL'];

                continue;
            }

            // ssai's own item-level synthesis. Nothing here re-reads the pictures.
            $metadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];
            $mapped = [] !== $metadata ? $this->claims->mapMetadata($metadata) : null;

            if (null === $mapped) {
                ++$skipped;
                $rows[] = [$remoteId, $this->titleFor($remote) ?? '—', count($images), 'skipped: AI synthesis has not run'];

                continue;
            }

            $clientId = 'ssai:' . $tenantId . ':' . $remoteId;
            $item = $this->items->findOneByClientId($clientId);
            $isNew = null === $item;

            if ($dryRun) {
                $rows[] = [
                    $remoteId,
                    mb_substr((string) ($mapped['title'] ?? '—'), 0, 38),
                    count($images),
                    ($isNew ? 'would create' : 'would update') . ($mapped['state'] ? ' · ' . $mapped['state'] : ''),
                ];
                $isNew ? ++$created : ++$updated;

                continue;
            }

            $item ??= new Item($clientId, $captureProfile);
            $item->setTitle($mapped['title'] ?? $this->titleFor($remote));
            $item->setDescription($this->descriptionFor($mapped));
            // A placeholder. ssai does not estimate value yet, and everything here
            // is created as a DRAFT, so Dave prices it before anything goes live.
            if (null === $item->getPrice()) {
                $item->setPrice($price);
            }
            $item->setAttributes($this->attributesFrom($mapped));

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
            $rows[] = [
                $remoteId,
                mb_substr((string) $item->getTitle(), 0, 38),
                count($images),
                ($isNew ? 'created' : 'updated') . ($mapped['state'] ? ' · ' . $mapped['state'] : ''),
            ];
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

            // archiveUrl FIRST: ssai's `archive` imgproxy preset, which is the same
            // pixels as the S3 derivative (2560x1668) re-encoded as webp — 513 KB
            // against 833 KB, measured. Etsy wants BYTES rather than a URL, so the
            // adapter pays for that transfer twice; eBay and Mercado Libre take the
            // URL and we pay nothing. Etsy accepts webp and transcodes it itself.
            //
            // s3Url next, and it stays the fallback that matters: it is the durable
            // one, written once mediary's TRANSITION_UPLOAD has run, and it works
            // whether or not imgproxy is reachable. largeUrl often points at an
            // imgproxy running on whichever laptop did the scanning — those resolve
            // while that machine is awake and 530 the rest of the time, which is not
            // something to hand a marketplace.
            foreach (['archiveUrl', 's3Url', 'largeUrl', 'originalUrl', 'previewUrl'] as $key) {
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

    /**
     * Whether every image is on durable storage.
     *
     * An item whose pictures live on a laptop's imgproxy can be imported and then
     * never listed, so it is better not to import it: the review screen fills with
     * things that cannot go out, and the reason is invisible.
     *
     * @param array<string, mixed> $remote
     */
    private function isUploaded(array $remote): bool
    {
        $images = (array) ($remote['images'] ?? []);

        if ([] === $images) {
            return false;
        }

        foreach ($images as $image) {
            if (!is_array($image) || !is_string($image['s3Url'] ?? null) || '' === $image['s3Url']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The listing description.
     *
     * ssai's description already covers both sides of the postcard. The synthesis
     * notes and the place are appended because a buyer scanning results wants the
     * where before the prose, and Etsy shows the first line hardest.
     *
     * @param array<string, mixed> $mapped
     */
    private function descriptionFor(array $mapped): ?string
    {
        $parts = array_filter([
            $mapped['description'] ?? null,
            $mapped['synthesisNotes'] ?? null,
            null !== ($mapped['place'] ?? null) ? 'Location: ' . $mapped['place'] : null,
            null !== ($mapped['date'] ?? null) ? 'Estimated date: ' . $mapped['date'] : null,
        ], static fn (mixed $p): bool => is_string($p) && '' !== trim($p));

        return [] === $parts ? null : implode("\n\n", $parts);
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

    /**
     * The structured half of the mapping. The description already reads well for a
     * human; this is the part a marketplace indexes on, so it is stored as fields
     * rather than prose.
     *
     * Place is flattened into tags because that is how buyers browse -- someone
     * shopping for a New Hampshire postcard searches the state, not the hotel. The
     * mapper has already put the depicted place first, so the ordering here is the
     * ordering a marketplace will truncate to its own tag limit.
     *
     * @param array<string, mixed> $mapped
     *
     * @return array<string, string|list<string>>
     */
    private function attributesFrom(array $mapped): array
    {
        $attributes = [];

        $tags = $mapped['tags'] ?? [];
        if (is_array($tags) && [] !== $tags) {
            $attributes['tags'] = array_values(array_filter($tags, 'is_string'));
        }

        foreach (['place', 'landmark', 'city', 'state', 'country', 'type', 'date'] as $key) {
            if (is_string($mapped[$key] ?? null) && '' !== $mapped[$key]) {
                $attributes[$key] = $mapped[$key];
            }
        }

        // Etsy's own vocabulary, and the only two fields it demands on every
        // listing. Mapping the era here means the adapter never has to guess.
        if (is_string($mapped['whenMade'] ?? null)) {
            $attributes['when_made'] = $mapped['whenMade'];
        }

        return $attributes;
    }
}
