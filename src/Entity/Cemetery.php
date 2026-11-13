<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Cemetery
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $boundary = null;

    public function __construct(#[ORM\Column(length: 200)] private string $name) {}
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getBoundary(): ?array { return $this->boundary; }
}
