<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
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

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['item:read', 'item:write'])]
    private ?string $description = null;

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

    /** @var Collection<int, Media> */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['item:read'])]
    private Collection $media;

    public function __construct(string $clientId)
    {
        $this->clientId = $clientId;
        $this->marking = ItemFlow::PLACE_NEW;
        $this->createdAt = new \DateTimeImmutable();
        $this->media = new ArrayCollection();
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
