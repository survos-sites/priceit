<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PricingSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PricingSuggestionServiceTest extends TestCase
{
    public function testLookupPreservesImagesAndStructuredOutput(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.anthropic.com/v1/messages', $url);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('claude-opus-5', $body['model']);
            self::assertSame(16000, $body['max_tokens']);
            self::assertSame('json_schema', $body['output_config']['format']['type']);
            self::assertArrayHasKey('effort', $body['output_config']);
            self::assertContains('priceUsd', $body['output_config']['format']['schema']['required']);
            self::assertArrayHasKey('tagPriceUsd', $body['output_config']['format']['schema']['properties']);
            $content = $body['messages'][0]['content'];
            self::assertCount(4, $content);
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame('image', $content[$i]['type']);
                self::assertSame('image/jpeg', $content[$i]['source']['media_type']);
                self::assertSame(base64_encode('photo'.$i), $content[$i]['source']['data']);
            }
            self::assertStringContainsString('Maker mark', $content[3]['text']);

            return self::response('{"title":"Bowl","priceUsd":12}', 'end_turn', true);
        });
        $photos = array_map(fn ($i) => ['bytes' => 'photo'.$i, 'mime' => 'image/jpeg'], range(0, 3));
        $result = $this->service($client)->lookup($photos, 'Maker mark');
        self::assertTrue($result['ok'], $result['message'] ?? '');
        self::assertSame('Bowl', $result['data']['title']);
    }

    public function testInventorySuggestionUsesAllEvidenceWithoutOverwritingReviewedFields(): void
    {
        $item = new \App\Entity\Item('inventory-1', \App\Profile\CaptureProfile::MedicalEquipment);
        $item->setAttributes(['source' => 'inventory']);
        $item->setTitle('Reviewed crutches');
        $item->setDescription('Staff confirmed');
        $item->setTranscript('First note: shelf B. Second note: missing rubber tip.');
        $item->saveToCollection(new \App\Entity\InventoryCollection('Medical loan closet'));
        foreach (['label.jpg', 'item.jpg', 'detail.jpg'] as $filename) {
            $item->addMedia((new \App\Entity\Media(\App\Entity\MediaKind::Photo))->setFilename($filename)->setMimeType('image/jpeg'));
        }
        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('read')->willReturn('photo-bytes');
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('priceUsd', $body['output_config']['format']['schema']['properties']);
            self::assertCount(4, $body['messages'][0]['content']);
            self::assertStringContainsString('missing rubber tip', $body['messages'][0]['content'][3]['text']);
            return self::response('{"title":"Suggested crutches","description":"AI description","category":"Mobility","equipmentType":"Crutches","confidence":"high"}', 'end_turn');
        });
        $service = new PricingSuggestionService($this->createStub(EntityManagerInterface::class), $storage, new NullLogger(), 'test-key', 'claude-opus-5', $client);
        self::assertTrue($service->suggest($item)['ok']);
        self::assertSame('Reviewed crutches', $item->getTitle());
        self::assertSame('Staff confirmed', $item->getDescription());
        self::assertSame('Suggested crutches', $item->getInventorySuggestion()['title']);
        self::assertNull($item->getPrice());
    }

    #[DataProvider('failureResponses')]
    public function testFailuresAreReturned(string $text, string $stop, int $status, string $expected): void
    {
        $response = $status === 200 ? self::response($text, $stop) : new MockResponse('{"error":{"message":"Unauthorized"}}', ['http_code' => $status]);
        $result = $this->service(new MockHttpClient($response))->lookup([['bytes' => 'photo', 'mime' => 'image/jpeg']]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString($expected, $result['message']);
    }

    public static function failureResponses(): iterable
    {
        yield 'refusal' => ['Sorry', 'refusal', 200, 'declined'];
        yield 'truncated' => ['{"title":', 'max_tokens', 200, 'cut short'];
        yield 'invalid json' => ['not json', 'end_turn', 200, 'Could not read'];
        yield 'scalar json' => ['42', 'end_turn', 200, 'not a JSON object'];
        yield 'http failure' => ['', 'end_turn', 401, 'AI call failed'];
    }

    private static function response(string $text, string $stop, bool $thinking = false): MockResponse
    {
        $content = $thinking ? [['type' => 'thinking', 'thinking' => 'Inspecting the maker mark', 'signature' => 'test']] : [];
        $content[] = ['type' => 'text', 'text' => $text];

        return new MockResponse(json_encode(['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]], JSON_THROW_ON_ERROR));
    }

    private function service(MockHttpClient $client): PricingSuggestionService
    {
        return new PricingSuggestionService($this->createStub(EntityManagerInterface::class), $this->createStub(FilesystemOperator::class), new NullLogger(), 'test-key', 'claude-opus-5', $client);
    }
}
