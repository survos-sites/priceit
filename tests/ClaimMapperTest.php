<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\ClaimMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are real claim shapes taken from daveglassner.scanstation.ai —
 * including the detail that dcterms:spatial arrives as {name, basis}, not a
 * string, which is the kind of thing a hand-written fixture gets wrong.
 */
final class ClaimMapperTest extends TestCase
{
    private function claim(string $predicate, mixed $value, string $confidence = '1'): array
    {
        return ['predicate' => $predicate, 'value' => $value, 'confidence' => $confidence];
    }

    public function testSplitsACommaSeparatedPlace(): void
    {
        $out = (new ClaimMapper())->map([
            $this->claim('dcterms:spatial', ['name' => 'Parrot Jungle, Miami, Florida', 'basis' => 'Directly visible']),
        ]);

        self::assertSame('Miami', $out['city']);
        self::assertSame('Florida', $out['state']);
        self::assertSame('FL', $out['stateCode']);
        self::assertSame('United States', $out['country']);
    }

    public function testSplitsAPlaceWithNoCommas(): void
    {
        // "West Yellowstone Montana" — the state is the tail of one string.
        $out = (new ClaimMapper())->map([
            $this->claim('dcterms:spatial', ['name' => 'West Yellowstone Montana']),
        ]);

        self::assertSame('West Yellowstone', $out['city']);
        self::assertSame('Montana', $out['state']);
        self::assertSame('MT', $out['stateCode']);
    }

    public function testAStateAloneHasNoCity(): void
    {
        $out = (new ClaimMapper())->map([$this->claim('dcterms:spatial', ['name' => 'Florida'])]);

        self::assertSame('Florida', $out['state']);
        self::assertNull($out['city']);
    }

    public function testALandmarkWithNoStateIsNotGuessedAt(): void
    {
        // Yellowstone spans three states. Inventing one would be worse than none.
        $out = (new ClaimMapper())->map([$this->claim('dcterms:spatial', ['name' => 'Yellowstone National Park'])]);

        self::assertNull($out['state']);
        self::assertNull($out['country']);
        self::assertSame('Yellowstone National Park', $out['place']);
    }

    public function testPlaceTagsComeBeforeSubjectTags(): void
    {
        // Etsy allows 13 tags. Someone searches "Miami Florida", not "scenic",
        // so the generic ones are what should fall off the end.
        $out = (new ClaimMapper())->map([
            $this->claim('dcterms:spatial', ['name' => 'Miami, Florida']),
            $this->claim('dcterms:subject', 'postcard'),
            $this->claim('dcterms:subject', 'scenic'),
        ]);

        self::assertSame(['Miami', 'Florida', 'United States', 'postcard', 'scenic'], $out['tags']);
    }

    public function testTagsAreDedupedAndLengthCapped(): void
    {
        $out = (new ClaimMapper())->map([
            $this->claim('dcterms:spatial', ['name' => 'Miami, Florida']),
            $this->claim('dcterms:subject', 'Miami'),
            $this->claim('dcterms:subject', 'a tag far too long for etsy to accept here'),
        ]);

        self::assertSame(['Miami', 'Florida', 'United States'], $out['tags']);
    }

    #[DataProvider('dates')]
    public function testMapsADateOntoAnEtsyBucket(string $claimed, ?string $expected): void
    {
        self::assertSame($expected, (new ClaimMapper())->etsyWhenMade($claimed));
    }

    public static function dates(): iterable
    {
        yield 'circa' => ['ca. 1908', '1900s'];
        yield 'decade' => ['1940s', '1940s'];
        yield 'twenties' => ['1920s', '1920s'];
        yield 'mid-century' => ['ca. 1955', '1950s'];
        yield 'unparseable' => ['very old', null];
    }

    public function testPrefersAbstractOverDescription(): void
    {
        $out = (new ClaimMapper())->map([
            $this->claim('dcterms:description', 'short'),
            $this->claim('dcterms:abstract', 'A fuller sentence about the postcard.'),
        ]);

        self::assertSame('A fuller sentence about the postcard.', $out['description']);
    }

    public function testOcrTakesTheLongestValueNotTheFirst(): void
    {
        // A postcard's back carries far more text than its front, and claims
        // arrive per image — first would usually be the emptier side.
        $out = (new ClaimMapper())->map([
            $this->claim('ai:ocrText', 'front'),
            $this->claim('ai:ocrText', 'THIS SPACE MAY BE USED FOR CORRESPONDENCE — US POSTAGE'),
        ]);

        self::assertStringContainsString('CORRESPONDENCE', (string) $out['ocr']);
    }

    public function testEstimatedValueIsReadIfSsaiEverEmitsIt(): void
    {
        // Not produced yet; wired so the pipeline can start emitting it without
        // a change here.
        $out = (new ClaimMapper())->map([$this->claim('schema:price', '12.00', '0')]);

        self::assertSame('12.00', $out['estimatedValue']);
        self::assertSame('0', $out['valueConfidence']);
    }

    public function testEmptyClaimsProduceNothingRatherThanFailing(): void
    {
        $out = (new ClaimMapper())->map([]);

        self::assertNull($out['title']);
        self::assertSame([], $out['tags']);
        self::assertNull($out['country']);
    }

    /**
     * The real Item.metadata for marac-0005's cockatoo postcard, verbatim from the
     * live API. This is the shape that matters most, and it is not obvious: spatial
     * is a LIST, and it contains two places for two different reasons.
     */
    public function testMapsRealItemMetadata(): void
    {
        $out = (new ClaimMapper())->mapMetadata([
            'dcterms:title' => 'Cockatoo. Parrot Jungle, Miami, Florida',
            'dcterms:type' => 'postcard',
            'dcterms:description' => "A postcard depicting 'Topsy,' a colorful Leadbeater cockatoo at Parrot Jungle, Miami, Florida.",
            'dcterms:abstract' => "Postcard of 'Topsy,' a Leadbeater cockatoo, from Parrot Jungle, Miami.",
            // Depicted place FIRST; the second is where it was posted to.
            'dcterms:spatial' => ['Miami, Florida', 'Indianapolis, Indiana'],
            'foaf:Person' => ['Miss Betty J. Rice'],
            'ssai:primaryImageText' => 'DPHOLT moi ajBune joirog ojypyooD',
            'ssai:hasText' => true,
            'ssai:imageCount' => 2,
            'ssai:itemSynthesisNotes' => 'Front shows the colorful cockatoo; reverse contains a vacation message and a postmark.',
        ]);

        self::assertSame('Cockatoo. Parrot Jungle, Miami, Florida', $out['title']);
        self::assertSame('postcard', $out['type']);

        // The place being SOLD, not the addressee's town.
        self::assertSame('Miami', $out['city']);
        self::assertSame('Florida', $out['state']);
        self::assertSame('United States', $out['country']);

        self::assertContains('Miami', $out['tags']);
        self::assertContains('Florida', $out['tags']);

        self::assertSame(['Miss Betty J. Rice'], $out['people']);
        self::assertStringContainsString('postmark', (string) $out['synthesisNotes']);
        self::assertNotNull($out['ocr']);
    }

    public function testTheAddresseesTownDoesNotBecomeTheListingPlace(): void
    {
        // Indianapolis is where it was POSTED TO. Tagging the listing with it would
        // put the postcard in front of the wrong buyers.
        $out = (new ClaimMapper())->mapMetadata([
            'dcterms:spatial' => ['Miami, Florida', 'Indianapolis, Indiana'],
        ]);

        self::assertSame('Miami', $out['city']);
        self::assertNotContains('Indianapolis', $out['tags']);
    }

    public function testMetadataAndClaimPathsReturnTheSameShape(): void
    {
        $mapper = new ClaimMapper();

        self::assertSame(
            array_keys($mapper->map([])),
            array_keys($mapper->mapMetadata([])),
            'a caller must not care which source produced the fields',
        );
    }

    public function testWashingtonDcIsNotWashingtonState(): void
    {
        // Real value from marac-0005. Walking right, "D.C." is not in the state
        // table and "Washington" is — so without an alias this tagged a DC
        // postcard for Seattle buyers.
        $out = (new ClaimMapper())->mapMetadata(['dcterms:spatial' => ['Washington, D.C.', 'Salem, Oregon']]);

        self::assertSame('District of Columbia', $out['state']);
        self::assertSame('DC', $out['stateCode']);
        self::assertSame('Washington', $out['city']);
        self::assertNotContains('Oregon', $out['tags'], 'Salem is where it was posted to');
    }
}

