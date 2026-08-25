<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;

final readonly class QuickbaseInventoryPublisher
{
    private const APP = 'lions';
    private const TABLE = 'inventory';

    public function __construct(
        private EntityManagerInterface $em,
        private ?QuickbaseClientInterface $quickbase = null,
        private ?QuickbaseAppRegistry $apps = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->quickbase && null !== $this->apps;
    }

    /** @return array<int, mixed> */
    public function payload(Item $item): array
    {
        $table = $this->apps()->table(self::APP, self::TABLE);
        $fields = $table['fields'];

        $payload = [
            $this->field($fields, 'sku') => $item->getClientId(),
            $this->field($fields, 'name') => $item->getTitle() ?? sprintf('PriceIt item %s', $item->getId() ?? 'new'),
            $this->field($fields, 'description') => $item->getDescription() ?? '',
        ];

        if (null !== $item->getQuickbaseInventoryRecordId()) {
            $payload[$this->field($fields, 'record_id')] = $item->getQuickbaseInventoryRecordId();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function publish(Item $item): array
    {
        $table = $this->apps()->table(self::APP, self::TABLE);
        $recordIdField = $this->field($table['fields'], 'record_id');
        $result = $this->quickbase()->upsertRecords(
            tableId: $table['id'],
            records: [$this->payload($item)],
            fieldsToReturn: [$recordIdField],
        );

        $recordId = self::returnedRecordId($result, $recordIdField);

        $item
            ->setQuickbaseInventoryRecordId((int) $recordId)
            ->setQuickbaseExportedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $result;
    }

    /** @param array<string, mixed> $result */
    private static function returnedRecordId(array $result, int $fieldId): int
    {
        $data = $result['data'] ?? null;
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            throw new \UnexpectedValueException('Quickbase did not return Inventory record data.');
        }

        $field = $data[0][$fieldId] ?? null;
        if (!is_array($field)) {
            throw new \UnexpectedValueException('Quickbase did not return the Inventory record ID field.');
        }

        $recordId = $field['value'] ?? null;
        if (!is_int($recordId) && !(is_string($recordId) && ctype_digit($recordId))) {
            throw new \UnexpectedValueException('Quickbase returned an invalid Inventory record ID.');
        }

        return (int) $recordId;
    }

    /**
     * @param array<string, int> $fields
     */
    private function field(array $fields, string $name): int
    {
        return $fields[$name]
            ?? throw new \LogicException(sprintf('Quickbase field "%s.%s.%s" is not configured.', self::APP, self::TABLE, $name));
    }

    private function quickbase(): QuickbaseClientInterface
    {
        return $this->quickbase
            ?? throw new \LogicException('Quickbase publishing is temporarily disabled.');
    }

    private function apps(): QuickbaseAppRegistry
    {
        return $this->apps
            ?? throw new \LogicException('Quickbase publishing is temporarily disabled.');
    }
}
