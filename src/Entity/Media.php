<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
// Vich 3.0 dropped Mapping\Annotation; the attributes moved to Mapping\Attribute
// with identical names and named arguments.
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[Vich\Uploadable]
#[ApiResource(operations: [new Get()], normalizationContext: ['groups' => ['media:read']])]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['item:read', 'media:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Item $item = null;

    #[ORM\Column(enumType: MediaKind::class)]
    #[Groups(['item:read', 'media:read'])]
    private MediaKind $kind;

    #[Vich\UploadableField(mapping: 'item_media', fileNameProperty: 'filename', size: 'size', mimeType: 'mimeType')]
    private ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['item:read', 'media:read'])]
    private ?string $filename = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['item:read', 'media:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(MediaKind $kind)
    {
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?Item
    {
        return $this->item;
    }

    public function setItem(?Item $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    public function setFile(?File $file): static
    {
        $this->file = $file;

        // VichUploaderBundle only persists a change when at least one mapped column is touched
        // (see its own docs) -- forcing updatedAt here is what actually triggers the upload.
        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }
}
