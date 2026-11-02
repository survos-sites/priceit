<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Collections in this installation's shared, authenticated staff workspace. */
#[ORM\Entity]
class InventoryCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 120, unique: true)]
        private string $name,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
}
