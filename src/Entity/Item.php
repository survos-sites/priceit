<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Profile\CaptureProfile;
use App\Repository\ItemRepository;
use App\Workflow\ItemFlow;
use Survos\StateBundle\Traits\MarkingTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Patch(),
    ],
    normalizationContext: ['groups' => ['item:read']],
    denormalizationContext: ['groups' => ['item:write']],
    order: ['createdAt' => 'DESC'],
)]
class Item
{
    use MarkingTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['item:read'])]
    private ?int $id = null;

    /**
     * Client-generated id for the capture session (crypto.randomUUID() in the browser).
     * Lets the offline outbox retry an upload without creating a duplicate Item.
     */
    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['item:read'])]
    private string $clientId;


    /**
     * Ticked on the capture screen: print as soon as the AI has a price,
     * without waiting for anyone to approve it.
     */
    #[ORM\Column]
    #[Groups(['item:read'])]
    public bool $printRequested = false;

    /**
     * Chosen on the capture screen. Decides what the model is asked for, what the label
     * carries, and whether confirming pushes the item to the loan closet.
     */
    #[ORM\Column(length: 32, enumType: CaptureProfile::class, options: ['default' => 'garage_sale'])]
    #[Groups(['item:read'])]
    private CaptureProfile $profile = CaptureProfile::GarageSale;

    /**
     * Human-readable identifier printed on a loan-closet label and used as the natural key in
     * Quickbase. Derived from the item's own sequence rather than hashed or randomized, so the
     * number on the sticker is the number in the table.
     */
    #[ORM\Column(length: 32, nullable: true, unique: true)]
    #[Groups(['item:read'])]
    private ?string $assetNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $category = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $equipmentType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $price = null;

    /** Raw transcript of the spoken note, if audio was captured. Filled in by the AI pipeline. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['item:read'])]
    private ?string $transcript = null;

    #[ORM\Column]
    #[Groups(['item:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['item:read'])]
    private ?int $quickbaseInventoryRecordId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['item:read'])]
    private ?\DateTimeImmutable $quickbaseExportedAt = null;

    /**
     * Where this item is listed, keyed by CONNECTION name: dave, chijal, marcela.
     *
     * Connection, not provider. Two sellers can share one marketplace -- chijal and
     * Marcela are both mercadolibre/MLM -- and keying by provider would make one
     * seller's listing look like the other's, so listing an item for chijal would
     * report it as already listed for Marcela.
     *
     * A map rather than a column per marketplace: the set of connections is
     * configuration rather than schema, so adding a seller is not a migration.
     *
     * Each entry is {externalId, url, listedAt}. externalId is whatever handle that
     * provider's own write operations accept -- eBay's OFFER id, not its listing id;
     * Mercado Libre's item id.
     *
     * @var array<string, array{externalId: string, url: string|null, listedAt: string}>
     */
    #[ORM\Column(options: ['default' => '{}'])]
    #[Groups(['item:read'])]
    private array $marketplaceListings = [];

    /**
     * Provider-neutral facets describing the item: tags, place, materials, and the
     * era/authorship pair Etsy calls when_made/who_made.
     *
     * These come from whoever read the item -- today, ssai's synthesis by way of
     * ClaimMapper -- and are kept verbatim rather than folded into the description,
     * because a marketplace wants them as structured fields. Country/state/city
     * tags in particular are how postcard buyers actually browse.
     *
     * Untyped on purpose: what a marketplace accepts is its business, and pinning a
     * schema here would mean a migration every time one adds a field.
     *
     * @var array<string, string|list<string>>
     */
    #[ORM\Column(options: ['default' => '{}'])]
    #[Groups(['item:read'])]
    private array $attributes = [];

    /** @var Collection<int, Media> */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['item:read'])]
    private Collection $media;

    public function __construct(string $clientId, CaptureProfile $profile = CaptureProfile::GarageSale)
    {
        $this->clientId = $clientId;
        $this->profile = $profile;
        $this->marking = ItemFlow::PLACE_NEW;
        $this->createdAt = new \DateTimeImmutable();
        $this->media = new ArrayCollection();
    }

    public function getProfile(): CaptureProfile
    {
        return $this->profile;
    }

    public function setProfile(CaptureProfile $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getAssetNumber(): ?string
    {
        return $this->assetNumber;
    }

    /**
     * Mint the asset number, once, from the item's own id.
     *
     * Only meaningful after a flush, which is the point: the id is a real sequence, so LC-00042
     * is short enough to read aloud over the phone and unique without a hash. Re-minting is a
     * no-op so a reprint never changes the number already stuck to the item.
     */
    public function assignAssetNumber(string $prefix = 'LC'): ?string
    {
        if (null !== $this->assetNumber || null === $this->id) {
            return $this->assetNumber;
        }

        return $this->assetNumber = sprintf('%s-%05d', $prefix, $this->id);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }


    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getEquipmentType(): ?string
    {
        return $this->equipmentType;
    }

    public function setEquipmentType(?string $equipmentType): static
    {
        $this->equipmentType = $equipmentType;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getTranscript(): ?string
    {
        return $this->transcript;
    }

    public function setTranscript(?string $transcript): static
    {
        $this->transcript = $transcript;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getQuickbaseInventoryRecordId(): ?int
    {
        return $this->quickbaseInventoryRecordId;
    }

    public function setQuickbaseInventoryRecordId(?int $quickbaseInventoryRecordId): static
    {
        $this->quickbaseInventoryRecordId = $quickbaseInventoryRecordId;

        return $this;
    }

    public function getQuickbaseExportedAt(): ?\DateTimeImmutable
    {
        return $this->quickbaseExportedAt;
    }

    public function setQuickbaseExportedAt(?\DateTimeImmutable $quickbaseExportedAt): static
    {
        $this->quickbaseExportedAt = $quickbaseExportedAt;

        return $this;
    }

    /** @return array<string, array{externalId: string, url: string|null, listedAt: string}> */
    public function getMarketplaceListings(): array
    {
        return $this->marketplaceListings;
    }

    /** @return array{externalId: string, url: string|null, listedAt: string}|null */
    public function getListing(string $connection): ?array
    {
        return $this->marketplaceListings[$connection] ?? null;
    }

    /** Re-listing where a listing already exists would create a duplicate, not update one. */
    public function isListedOn(string $connection): bool
    {
        return isset($this->marketplaceListings[$connection]);
    }

    public function getListingUrl(string $connection): ?string
    {
        return $this->marketplaceListings[$connection]['url'] ?? null;
    }

    public function getListingExternalId(string $connection): ?string
    {
        return $this->marketplaceListings[$connection]['externalId'] ?? null;
    }

    public function getListedAt(string $connection): ?\DateTimeImmutable
    {
        $at = $this->marketplaceListings[$connection]['listedAt'] ?? null;

        return \is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    public function recordListing(string $connection, string $externalId, ?string $url = null): static
    {
        $this->marketplaceListings[$connection] = [
            'externalId' => $externalId,
            'url' => $url,
            'listedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        return $this;
    }

    public function forgetListing(string $connection): static
    {
        unset($this->marketplaceListings[$connection]);

        return $this;
    }

    /**
     * The SKU a marketplace keys inventory on.
     *
     * The client id is already unique and already travels with the item from the
     * phone, so it needs no second identity invented for it.
     */
    public function getSku(): string
    {
        return $this->clientId;
    }

    /** @return Collection<int, Media> */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addMedia(Media $media): static
    {
        if (!$this->media->contains($media)) {
            $this->media->add($media);
            $media->setItem($this);
        }

        return $this;
    }

    /** @return list<Media> */
    public function getPhotos(): array
    {
        $photos = [];
        foreach ($this->media as $media) {
            if (MediaKind::Photo === $media->getKind()) {
                $photos[] = $media;
            }
        }

        return $photos;
    }

    public function getAudio(): ?Media
    {
        foreach ($this->media as $m) {
            if (MediaKind::Audio === $m->getKind()) {
                return $m;
            }
        }

        return null;
    }

    /** @return array<string, string|list<string>> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, string|list<string>> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        $tags = $this->attributes['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }
}
