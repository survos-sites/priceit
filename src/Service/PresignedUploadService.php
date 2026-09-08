<?php

declare(strict_types=1);

namespace App\Service;

use Aws\S3\S3Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hands the phone a URL it can PUT a photo to, without the bytes touching us.
 *
 * The old path was phone → multipart POST → Symfony → Vich → S3, so every byte
 * crossed the web process. On a phone in a garage that is the slowest hop in the
 * chain, it puts 8MB photos against the request body limit, and it ties a photo's
 * public URL to whatever host happened to serve the upload — which is why
 * priceit's images currently depend on a cloudflared tunnel.
 *
 * Uploading straight to S3 fixes all three, and lands the photo at the same kind
 * of durable public URL an imported ssai scan already has. Both then flow through
 * Media::$sourceUrl, so a captured photo and a scanned one stop being different
 * things downstream.
 *
 * Unavailable without credentials, which is the dev default — callers fall back to
 * the multipart route rather than failing.
 */
final readonly class PresignedUploadService
{
    public function __construct(
        private ?S3Client $s3 = null,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')]
        private string $bucket = '',
        #[Autowire('%env(AWS_S3_ACCESS_ID)%')]
        private string $accessId = '',
        #[Autowire('%env(default::MEDIA_PUBLIC_BASE)%')]
        private ?string $publicBase = null,
        #[Autowire('%env(S3_ENDPOINT)%')]
        private string $endpoint = '',
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->s3 && '' !== $this->bucket && '' !== $this->accessId;
    }

    /**
     * A presigned PUT for one photo.
     *
     * @return array{key: string, uploadUrl: string, publicUrl: string, expiresAt: string}
     */
    public function presign(string $clientId, int $index, string $contentType = 'image/jpeg'): array
    {
        if (!$this->isAvailable()) {
            throw new \LogicException('S3 is not configured; upload through /api/camera/capture instead.');
        }

        $extension = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heif' => 'heic',
            default => 'jpg',
        };

        // Keyed by client id, which the phone already generates and which is what
        // makes the capture itself idempotent. A retry overwrites rather than
        // littering the bucket with orphans.
        $key = sprintf(
            'uploads/%s/%s-%d.%s',
            date('Y/m'),
            preg_replace('/[^A-Za-z0-9._-]/', '_', $clientId) ?? 'item',
            $index,
            $extension,
        );

        $command = $this->s3->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        $expiresAt = new \DateTimeImmutable('+15 minutes');
        $request = $this->s3->createPresignedRequest($command, $expiresAt);

        return [
            'key' => $key,
            'uploadUrl' => (string) $request->getUri(),
            'publicUrl' => $this->publicUrlFor($key),
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Where the object will be readable once uploaded.
     *
     * The bucket carries a public-read policy, so this is a plain path — no second
     * signature, and nothing for a marketplace to fail to follow.
     */
    public function publicUrlFor(string $key): string
    {
        if (null !== $this->publicBase && '' !== $this->publicBase) {
            return rtrim($this->publicBase, '/') . '/' . ltrim($key, '/');
        }

        // Hetzner is path-style: https://fsn1.your-objectstorage.com/<bucket>/<key>
        return sprintf('%s/%s/%s', rtrim($this->endpoint, '/'), $this->bucket, ltrim($key, '/'));
    }
}
