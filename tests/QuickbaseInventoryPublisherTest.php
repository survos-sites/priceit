<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Item;
use App\Service\QuickbaseInventoryPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;

final class QuickbaseInventoryPublisherTest extends TestCase
{
    public function testIsUnavailableWithoutQuickbaseBundleServices(): void
    {
        $publisher = new QuickbaseInventoryPublisher($this->createStub(EntityManagerInterface::class));

        self::assertFalse($publisher->isAvailable());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Quickbase publishing is temporarily disabled.');
        $publisher->payload(new Item('priceit-client-id'));
    }

    public function testPublishesConfiguredFieldsAndStoresReturnedRecordId(): void
    {
        $item = (new Item('priceit-client-id'))
            ->setTitle('Talavera mug')
            ->setDescription('Blue floral ceramic mug.');

        $quickbase = $this->createMock(QuickbaseClientInterface::class);
        $quickbase->expects(self::once())
            ->method('upsertRecords')
            ->with(
                'bwa6visd6',
                [[6 => 'priceit-client-id', 15 => 'Talavera mug', 17 => 'Blue floral ceramic mug.']],
                null,
                [3],
            )
            ->willReturn(['data' => [[3 => ['value' => 42]]]]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $publisher = new QuickbaseInventoryPublisher($em, $quickbase, $this->apps());
        $publisher->publish($item);

        self::assertSame(42, $item->getQuickbaseInventoryRecordId());
        self::assertInstanceOf(\DateTimeImmutable::class, $item->getQuickbaseExportedAt());
    }

    private function apps(): QuickbaseAppRegistry
    {
        return new QuickbaseAppRegistry([
            'lions' => [
                'id' => 'bwa6visdy',
                'tables' => [
                    'inventory' => [
                        'id' => 'bwa6visd6',
                        'fields' => ['record_id' => 3, 'sku' => 6, 'name' => 15, 'description' => 17],
                    ],
                ],
            ],
        ]);
    }
}
