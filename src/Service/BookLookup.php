<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BookLookup
{
    public function __construct(private HttpClientInterface $http, private CacheInterface $cache) {}

    public static function isbn(string $input): string
    {
        $isbn = strtoupper(str_replace(['-', ' '], '', trim($input)));
        if (preg_match('/^\d{9}[\dX]$/D', $isbn)) {
            $sum = 0;
            for ($i = 0; $i < 10; ++$i) $sum += (10 - $i) * ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]);
            if ($sum % 11 !== 0) throw new \InvalidArgumentException('Invalid ISBN checksum.');
            $isbn = '978'.substr($isbn, 0, 9);
            $sum = 0;
            for ($i = 0; $i < 12; ++$i) $sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1);
            return $isbn.((10 - $sum % 10) % 10);
        }
        if (!preg_match('/^97[89]\d{10}$/D', $isbn)) throw new \InvalidArgumentException('Enter a book ISBN, not a general product barcode.');
        $sum = 0;
        for ($i = 0; $i < 13; ++$i) $sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1);
        if ($sum % 10 !== 0) throw new \InvalidArgumentException('Invalid ISBN checksum.');
        return $isbn;
    }

    public function lookup(string $input): array
    {
        $isbn = self::isbn($input);
        return $this->cache->get('book.isbn.'.$isbn, function (ItemInterface $item) use ($isbn): array {
            $item->expiresAfter(86400);
            try {
                $data = $this->http->request('GET', 'https://www.googleapis.com/books/v1/volumes', [
                    'query' => ['q' => 'isbn:'.$isbn, 'maxResults' => 10], 'timeout' => 5,
                ])->toArray();
            } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
                return $this->openLibrary($isbn);
            }
            foreach ($data['items'] ?? [] as $volume) {
                $info = $volume['volumeInfo'] ?? [];
                foreach ($info['industryIdentifiers'] ?? [] as $identifier) {
                    try { $match = self::isbn($identifier['identifier'] ?? ''); }
                    catch (\InvalidArgumentException) { continue; }
                    if ($match === $isbn) return [
                        'isbn' => $isbn, 'found' => true, 'title' => $info['title'] ?? $isbn,
                        'authors' => $info['authors'] ?? [], 'publisher' => $info['publisher'] ?? null,
                        'publishedDate' => $info['publishedDate'] ?? null, 'source' => 'Google Books',
                    ];
                }
            }
            return $this->openLibrary($isbn);
        });
    }

    private function openLibrary(string $isbn): array
    {
        $missing = ['isbn' => $isbn, 'found' => false, 'title' => $isbn, 'authors' => []];
        $response = $this->http->request('GET', 'https://openlibrary.org/isbn/'.$isbn.'.json', [
            'timeout' => 8, 'headers' => ['User-Agent' => 'InventoryIt/1.0 (https://github.com/survos-sites/priceit)'],
        ]);
        if ($response->getStatusCode() === 404) return $missing;
        $book = $response->toArray();
        foreach (array_merge($book['isbn_10'] ?? [], $book['isbn_13'] ?? []) as $identifier) {
            try { $match = self::isbn($identifier); }
            catch (\InvalidArgumentException) { continue; }
            if ($match === $isbn) return [
                'isbn' => $isbn, 'found' => true, 'title' => $book['title'] ?? $isbn,
                'authors' => [], 'authorReferences' => $book['authors'] ?? [],
                'publisher' => implode(', ', $book['publishers'] ?? []),
                'publishedDate' => $book['publish_date'] ?? null, 'source' => 'Open Library',
            ];
        }
        return $missing;
    }

}
