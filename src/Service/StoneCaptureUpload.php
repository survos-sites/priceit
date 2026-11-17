<?php

declare(strict_types=1);
namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class StoneCaptureUpload
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(default::IIT_CAPTURE_URL)%')] private ?string $url,
        #[Autowire('%env(default::IIT_CAPTURE_KEY)%')] private ?string $key,
    ) {}

    public function upload(UploadedFile $file, string $json, string $operatorId): array
    {
        if (!$this->url || !$this->key) throw new \RuntimeException('SSAI upload is not configured. Photos remain on this device.');
        if (!$file->isValid() || $file->getSize() > 25 * 1024 * 1024 || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) throw new \InvalidArgumentException('Upload a JPEG, PNG or WebP image of at most 25 MiB.');
        if (strlen($json) > 16000) throw new \InvalidArgumentException('Capture metadata is too large.');
        try { $metadata = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Invalid capture metadata.'); }
        if (!is_array($metadata) || !is_string($metadata['captureId'] ?? null)) throw new \InvalidArgumentException('Capture ID is required.');
        // The browser cannot choose another user's namespace in SSAI.
        $metadata['operatorId'] = hash('sha256', 'iit:'.$operatorId);
        $form = new FormDataPart([
            'file' => DataPart::fromPath($file->getPathname(), 'capture.jpg', $file->getMimeType()),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        $response = $this->http->request('POST', rtrim($this->url, '/').'/internal/mobile-captures', [
            'headers' => $form->getPreparedHeaders()->toArray() + ['X-Capture-Key' => $this->key],
            'body' => $form->bodyToString(), 'timeout' => 30, 'max_duration' => 60,
            'max_redirects' => 0,
        ]);
        $status = $response->getStatusCode();
        if ($status === 409) throw new \DomainException('SSAI rejected conflicting capture evidence. Keep the local photo and contact the operator.');
        if ($status === 422 || $status === 413) throw new \InvalidArgumentException('SSAI rejected the image or capture metadata. Keep the local photo for review.');
        if ($status !== 200) throw new \RuntimeException('SSAI did not confirm storage. The photo remains queued on this device.');
        $receipt = $response->toArray();
        if (($receipt['stored'] ?? false) !== true || ($receipt['captureId'] ?? null) !== $metadata['captureId'] || !is_string($receipt['imageId'] ?? null) || !is_string($receipt['intakeCode'] ?? null) || ($receipt['sha256'] ?? null) !== hash_file('sha256', $file->getPathname())) {
            throw new \RuntimeException('SSAI storage receipt did not match the uploaded photo. It remains queued.');
        }
        return array_intersect_key($receipt, array_flip(['stored', 'captureId', 'imageId', 'intakeCode', 'sha256', 'processing']));
    }
}
