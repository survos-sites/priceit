<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\ClaimMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaimMapper::class)]
final class ClaimMapperValueTest extends TestCase
{
    public function testSituationalValuesAreKeyedBySituation(): void
    {
        $mapped = (new ClaimMapper())->mapMetadata([
            'dcterms:title' => 'Mt. Washington Hotel',
            'schema:value' => [
                ['situation' => 'resale', 'low' => '8.00', 'high' => '15.00', 'currency' => 'USD',
                 'confidence' => 0.55, 'basis' => 'common 1930s linen view card'],
                ['situation' => 'garageSale', 'low' => '1.00', 'high' => '3.00', 'currency' => 'USD',
                 'confidence' => 0.8],
            ],
        ]);

        self::assertSame(['resale', 'garageSale'], array_keys($mapped['values']));
        self::assertSame('15.00', $mapped['values']['resale']['high']);
        self::assertSame('common 1930s linen view card', $mapped['values']['resale']['basis']);
        self::assertNull($mapped['values']['garageSale']['basis']);
        self::assertSame(0.8, $mapped['values']['garageSale']['confidence']);
    }

    public function testAHalfFilledEstimateIsDroppedRatherThanRepaired(): void
    {
        // A partial estimate reaching a listing becomes an asking price nobody can
        // later identify as a guess, so it must not survive at all.
        $mapped = (new ClaimMapper())->mapMetadata([
            'schema:value' => [
                ['situation' => 'resale', 'low' => '8.00', 'currency' => 'USD', 'confidence' => 0.5],
                ['situation' => 'insurance', 'low' => '20.00', 'high' => '30.00', 'currency' => 'USD',
                 'confidence' => 0.35],
            ],
        ]);

        self::assertSame(['insurance'], array_keys($mapped['values']));
    }

    public function testItemsWithoutValuesGetAnEmptyArrayNotNull(): void
    {
        $mapped = (new ClaimMapper())->mapMetadata(['dcterms:title' => 'Cholla']);

        self::assertSame([], $mapped['values']);
    }
}
