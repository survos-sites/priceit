<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\DepotPrintClient;
use App\Service\PriceLabelService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DepotPrintClientTest extends TestCase
{
    public function testAPrintedLabelReportsTheDepotJob(): void
    {
        $result = $this->client(new MockResponse(
            json_encode(['ok' => true, 'id' => 39, 'status' => 'done']),
            ['http_code' => 200],
        ))->printLabel($this->item());

        self::assertTrue($result['ok']);
        self::assertStringContainsString('39', $result['message']);
    }

    public function testAnHtmlGatewayErrorReportsTheStatusNotAJsonParseFailure(): void
    {
        // cloudflared answers with an HTML 502 when the laptop holding the printer has slept
        // or dropped off wifi. Decoding that as JSON used to raise "Syntax error", which was
        // then reported as the reason printing failed -- so a sleeping laptop read as a bug
        // in the payload.
        $result = $this->client(new MockResponse(
            '<html><head><title>502 Bad Gateway</title></head><body>error</body></html>',
            ['http_code' => 502, 'response_headers' => ['content-type' => 'text/html']],
        ))->printLabel($this->item());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('502', $result['message']);
        self::assertStringNotContainsString('Syntax error', $result['message']);
    }

    public function testAnEmptyBodyStillReportsTheStatus(): void
    {
        $result = $this->client(new MockResponse('', ['http_code' => 503]))->printLabel($this->item());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('503', $result['message']);
    }

    public function testTheLabelIsAlwaysReturnedSoAFailureCanStillBePreviewed(): void
    {
        $result = $this->client(new MockResponse('', ['http_code' => 502]))->printLabel($this->item());

        self::assertStringContainsString('^XA', $result['zpl']);
    }

    private function client(MockResponse $response): DepotPrintClient
    {
        return new DepotPrintClient(
            new MockHttpClient($response),
            new PriceLabelService(),
            new NullLogger(),
            'https://laptop-depot.example.test',
            'default',
            '',
        );
    }

    private function item(): Item
    {
        $item = new Item('c-1', CaptureProfile::MedicalEquipment);
        (new \ReflectionProperty(Item::class, 'id'))->setValue($item, 142);
        $item->setTitle('Folding walker');
        $item->setDescription('Aluminum folding walker.');
        $item->assignAssetNumber();

        return $item;
    }
}
