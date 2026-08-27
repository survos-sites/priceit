<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\QuickbaseAppRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Pushes a tagged loan-closet item into the Quickbase Equipment table.
 *
 * Field IDs are resolved from the live schema by label rather than transcribed into YAML.
 * Quickbase addresses fields only by number, but those numbers are assigned when a field is
 * created, so a hand-maintained map is a copy of something the API already knows and drifts
 * the moment anyone adds a column. SchemaSteward owns the schema; this reads it.
 */
final class QuickbaseInventoryPublisher
{
    private const APP = 'closet';
    private const TABLE = 'Equipment';

    /** @var array<string, int>|null label => field ID, resolved once per process */
    private ?array $fields = null;
    private ?string $tableId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?QuickbaseClientInterface $quickbase = null,
        private readonly ?QuickbaseAppRegistry $apps = null,
        private readonly ?StorageInterface $storage = null,
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private readonly string $publicUrl = '',
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->quickbase && null !== $this->apps && $this->apps->has(self::APP);
    }

    /** @return array<int, mixed> */
    public function payload(Item $item): array
    {
        $assetNumber = $item->getAssetNumber()
            ?? throw new \LogicException(sprintf('Item %d has no asset number to publish under.', (int) $item->getId()));

        $payload = [
            $this->field('Asset Number') => $assetNumber,
            $this->field('Description') => $item->getDescription() ?? $item->getTitle() ?? '',
            $this->field('Status') => 'Available',
            $this->field('Added On') => $item->getCreatedAt()->format('Y-m-d'),
        ];

        $photoUrl = $this->photoUrl($item);
        if (null !== $photoUrl) {
            $payload[$this->field('Photo URL')] = $photoUrl;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function publish(Item $item): array
    {
        $result = $this->quickbase()->upsertRecords(
            tableId: $this->tableId(),
            records: [$this->payload($item)],
            // The asset number printed on the label is the key on both sides, so republishing
            // the same item updates its row instead of creating a second one.
            mergeFieldId: $this->field('Asset Number'),
            fieldsToReturn: [3],
        );

        $item
            ->setQuickbaseInventoryRecordId(self::returnedRecordId($result))
            ->setQuickbaseExportedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $result;
    }

    private function field(string $label): int
    {
        if (null === $this->fields) {
            $this->fields = [];
            foreach ($this->quickbase()->fields($this->tableId()) as $field) {
                if (\is_string($field['label'] ?? null) && \is_int($field['id'] ?? null)) {
                    $this->fields[$field['label']] = $field['id'];
                }
            }
        }

        return $this->fields[$label] ?? throw new \LogicException(sprintf(
            'Quickbase table "%s" has no field labelled "%s". Run quickbase:schema:sync %s in SchemaSteward.',
            self::TABLE,
            $label,
            self::APP,
        ));
    }

    private function tableId(): string
    {
        if (null !== $this->tableId) {
            return $this->tableId;
        }

        $appId = $this->apps()->resolve(self::APP);
        foreach ($this->quickbase()->tables($appId) as $table) {
            if (self::TABLE === ($table['name'] ?? null) && \is_string($table['id'] ?? null)) {
                return $this->tableId = $table['id'];
            }
        }

        throw new \LogicException(sprintf('Quickbase app "%s" has no "%s" table.', self::APP, self::TABLE));
    }

    /** The photo has to be reachable by whoever opens the record, so it is a URL, not bytes. */
    private function photoUrl(Item $item): ?string
    {
        $photo = $item->getPhotos()[0] ?? null;
        if (null === $photo || null === $this->storage) {
            return null;
        }

        $uri = $this->storage->resolveUri($photo, 'file');
        if (null === $uri || '' === $uri) {
            return null;
        }

        return str_starts_with($uri, 'http')
            ? $uri
            : rtrim($this->publicUrl, '/').'/'.ltrim($uri, '/');
    }

    /** @param array<string, mixed> $result */
    private static function returnedRecordId(array $result): int
    {
        $value = $result['data'][0][3]['value'] ?? null;

        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            throw new \UnexpectedValueException('Quickbase did not return an Equipment record ID.');
        }

        return (int) $value;
    }

    private function quickbase(): QuickbaseClientInterface
    {
        return $this->quickbase ?? throw new \LogicException('Quickbase is not configured.');
    }

    private function apps(): QuickbaseAppRegistry
    {
        return $this->apps ?? throw new \LogicException('Quickbase is not configured.');
    }
}
