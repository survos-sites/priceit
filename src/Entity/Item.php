<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\ItemRepository;
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

    #[ORM\Column(enumType: ItemStatus::class)]
    #[Groups(['item:read', 'item:write'])]
    private ItemStatus $status = ItemStatus::Captured;

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

    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['item:read'])]
    private Collection $media;

    public function __construct(string $clientId)
    {
        $this->clientId = $clientId;
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

    public function getStatus(): ItemStatus
    {
        return $this->status;
    }

    public function setStatus(ItemStatus $status): static
    {
        $this->status = $status;

        return $this;
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
        return array_values(array_filter(
            $this->media->toArray(),
            static fn (Media $m): bool => MediaKind::Photo === $m->getKind(),
        ));
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
