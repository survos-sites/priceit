<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Item;
use App\Profile\CaptureProfile;
use App\Service\QuickbaseInventoryPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\QuickbaseAppRegistry;

final class QuickbaseInventoryPublisherTest extends TestCase
{
    public function testIsUnavailableWithoutQuickbaseServices(): void
    {
        $publisher = new QuickbaseInventoryPublisher($this->createStub(EntityManagerInterface::class));

        self::assertFalse($publisher->isAvailable());
    }

    public function testIsUnavailableWhenTheClosetIsNotConfigured(): void
    {
        $publisher = new QuickbaseInventoryPublisher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(QuickbaseClientInterface::class),
            new QuickbaseAppRegistry(['lions' => ['id' => 'app-1', 'tables' => []]], 'demo.quickbase.com'),
        );

        self::assertFalse($publisher->isAvailable());
    }

    public function testFieldIdsAreReadFromTheLiveSchemaRatherThanConfigured(): void
    {
        // Quickbase addresses fields only by number, but those numbers are assigned at field
        // creation. A map in YAML is a copy of something the API already knows, and it drifts
        // the moment anyone adds a column.
        $quickbase = $this->createMock(QuickbaseClientInterface::class);
        $quickbase->method('tables')->willReturn([
            ['id' => 'tbl-other', 'name' => 'Clients'],
            ['id' => 'tbl-equip', 'name' => 'Equipment'],
        ]);
        $quickbase->expects(self::once())->method('fields')->with('tbl-equip')->willReturn($this->liveFields());
        $quickbase->expects(self::once())->method('upsertRecords')
            ->with(
                'tbl-equip',
                self::callback(static fn (array $records): bool => 'LC-00142' === $records[0][6]),
                // The printed asset number is the key on both sides, so a republish updates the
                // row instead of creating a second one for the same physical item.
                6,
                [3],
            )
            ->willReturn(['data' => [[3 => ['value' => 88]]]]);

        $publisher = $this->publisher($quickbase);
        $publisher->publish($this->item());
    }

    public function testPayloadCarriesTheDescriptionAndMarksTheItemAvailable(): void
    {
        $payload = $this->publisher($this->stubClient($this->liveFields()))->payload($this->item());

        self::assertSame('LC-00142', $payload[6]);
        self::assertSame('Aluminum folding walker, 5in wheels.', $payload[7]);
        self::assertSame('Available', $payload[8]);
    }

    public function testAnItemWithNoAssetNumberIsRefusedRatherThanGivenOne(): void
    {
        // Publishing under an invented key would put a row in the closet that no label matches.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no asset number');

        $this->publisher($this->stubClient($this->liveFields()))
            ->payload(new Item('c-1', CaptureProfile::MedicalEquipment));
    }

    public function testAMissingFieldNamesTheFixRatherThanFailingOpaquely(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('quickbase:schema:sync closet');

        $this->publisher($this->stubClient([['id' => 3, 'label' => 'Record ID#']]))->payload($this->item());
    }

    /** @param list<array{id: int, label: string}> $fields */
    private function stubClient(array $fields): QuickbaseClientInterface
    {
        $client = $this->createStub(QuickbaseClientInterface::class);
        $client->method('tables')->willReturn([['id' => 'tbl-equip', 'name' => 'Equipment']]);
        $client->method('fields')->willReturn($fields);

        return $client;
    }

    /** @return list<array{id: int, label: string}> */
    private function liveFields(): array
    {
        return [
            ['id' => 3, 'label' => 'Record ID#'],
            ['id' => 6, 'label' => 'Asset Number'],
            ['id' => 7, 'label' => 'Description'],
            ['id' => 8, 'label' => 'Status'],
            ['id' => 9, 'label' => 'Photo URL'],
            ['id' => 10, 'label' => 'Added On'],
        ];
    }

    private function publisher(QuickbaseClientInterface $quickbase): QuickbaseInventoryPublisher
    {
        return new QuickbaseInventoryPublisher(
            $this->createStub(EntityManagerInterface::class),
            $quickbase,
            new QuickbaseAppRegistry(['closet' => ['id' => 'app-closet', 'tables' => []]], 'demo.quickbase.com'),
        );
    }

    private function item(): Item
    {
        $item = new Item('priceit-client-id', CaptureProfile::MedicalEquipment);
        (new \ReflectionProperty(Item::class, 'id'))->setValue($item, 142);
        $item->setDescription('Aluminum folding walker, 5in wheels.');
        $item->assignAssetNumber();

        return $item;
    }
}
