<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\StoneCaptureUpload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StoneCaptureUploadTest extends TestCase
{
    private string $path;
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'iit-photo-');
        $image = imagecreatetruecolor(4, 4); imagejpeg($image, $this->path);
    }
    protected function tearDown(): void { unlink($this->path); }
    private function file(): UploadedFile { return new UploadedFile($this->path, 'stone.jpg', 'image/jpeg', null, true); }

    public function testReceiptChecksumAndServerAssignedOperator(): void
    {
        $receipt = ['stored' => true, 'captureId' => 'test-id', 'imageId' => 'image-1', 'intakeCode' => 'intake-1', 'sha256' => hash_file('sha256', $this->path)];
        $client = new MockHttpClient(function ($method, $url, $options) use ($receipt) {
            self::assertSame('POST', $method);
            self::assertSame('https://ssai.example/internal/mobile-captures', $url);
            self::assertStringContainsString(hash('sha256', 'iit:user-1'), $options['body']);
            self::assertStringNotContainsString('forged-operator', $options['body']);
            return new MockResponse(json_encode($receipt));
        });
        $uploader = new StoneCaptureUpload($client, 'https://ssai.example', 'test-key');
        self::assertSame($receipt, $uploader->upload($this->file(), '{"captureId":"test-id","operatorId":"forged-operator"}', 'user-1'));
    }
    public function testMismatchedChecksumIsNotAcknowledged(): void
    {
        $client = new MockHttpClient(new MockResponse('{"stored":true,"captureId":"test-id","imageId":"image-1","intakeCode":"intake-1","sha256":"wrong"}'));
        $this->expectException(\RuntimeException::class);
        (new StoneCaptureUpload($client, 'https://ssai.example', 'test-key'))->upload($this->file(), '{"captureId":"test-id"}', 'user-1');
    }
    public function testMissingConfigurationFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        (new StoneCaptureUpload(new MockHttpClient(), null, null))->upload($this->file(), '{"captureId":"test-id"}', 'user-1');
    }
}
