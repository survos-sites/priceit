<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\BookLookup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BookLookupTest extends TestCase
{
    public function testCanonicalIsbnAndExactEditionMatch(): void
    {
        self::assertSame('9780140328721', BookLookup::isbn('0-14-032872-6'));
        self::assertSame('9780804429573', BookLookup::isbn('0-8044-2957-X'));
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;
            return new MockResponse(json_encode(['items' => [
                ['volumeInfo' => ['title' => 'Wrong edition', 'industryIdentifiers' => [['identifier' => '9780804429573']]]],
                ['volumeInfo' => ['title' => 'Matilda', 'industryIdentifiers' => [['identifier' => '0140328726']]]],
            ]]));
        });
        $books = new BookLookup($client, new ArrayAdapter());
        self::assertSame('Matilda', $books->lookup('9780140328721')['title']);
        self::assertTrue($books->lookup('0140328726')['found']);
        self::assertSame(1, $calls);
    }

    public function testInvalidChecksumDoesNotCallProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BookLookup(new MockHttpClient(), new ArrayAdapter()))->lookup('9780140328722');
    }

    public function testUnmatchedProviderResultIsNotMisidentified(): void
    {
        $books = new BookLookup(new MockHttpClient([new MockResponse('{"items":[{"volumeInfo":{"title":"Unrelated"}}]}'), new MockResponse('{}', ['http_code' => 404])]), new ArrayAdapter());
        self::assertFalse($books->lookup('9780140328721')['found']);
    }
    public function testQuotaFailureFallsBackToOpenLibrary(): void
    {
        $books = new BookLookup(new MockHttpClient([
            new MockResponse('{}', ['http_code' => 429]),
            new MockResponse('{"title":"Fantastic Mr. Fox","isbn_13":["9780140328721"]}'),
        ]), new ArrayAdapter());
        $book = $books->lookup('9780140328721');
        self::assertSame('Fantastic Mr. Fox', $book['title']);
        self::assertSame('Open Library', $book['source']);
    }

}
