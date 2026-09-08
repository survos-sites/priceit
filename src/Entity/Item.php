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
    #[ORM\Column(length: 32, enumType: CaptureProfile::class, options: ['default' => 'auction'])]
    #[Groups(['item:read'])]
    private CaptureProfile $profile = CaptureProfile::Auction;

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
     * eBay's offer id -- the handle every eBay write accepts, including withdrawing
     * the listing. Set once the item is live.
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['item:read'])]
    private ?string $ebayOfferId = null;

    /** The public listing id, the one that appears in an ebay.com/itm/ URL. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['item:read'])]
    private ?string $ebayListingId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['item:read'])]
    private ?\DateTimeImmutable $ebayListedAt = null;

    /** @var Collection<int, Media> */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['item:read'])]
    private Collection $media;

    public function __construct(string $clientId, CaptureProfile $profile = CaptureProfile::Auction)
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

    public function getEbayOfferId(): ?string
    {
        return $this->ebayOfferId;
    }

    public function getEbayListingId(): ?string
    {
        return $this->ebayListingId;
    }

    public function getEbayListedAt(): ?\DateTimeImmutable
    {
        return $this->ebayListedAt;
    }

    /** Whether this item is already live on eBay. Re-listing would create a duplicate. */
    public function isListedOnEbay(): bool
    {
        return null !== $this->ebayOfferId;
    }

    /** The public URL of the listing, once there is one. */
    public function getEbayUrl(): ?string
    {
        return null !== $this->ebayListingId
            ? 'https://www.ebay.com/itm/'.$this->ebayListingId
            : null;
    }

    public function recordEbayListing(string $offerId, ?string $listingId): static
    {
        $this->ebayOfferId = $offerId;
        $this->ebayListingId = $listingId;
        $this->ebayListedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * The SKU eBay keys inventory on.
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
}
