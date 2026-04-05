<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity]
#[ORM\Table(name: "conversation")]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: "string", length: 20)]
    private ?string $type = null;

    #[ORM\Column(type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $created_at = null;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $is_archived = false;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $is_pinned = false;

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $created_at): self { $this->created_at = $created_at; return $this; }
    public function isArchived(): bool { return $this->is_archived; }
    public function setIsArchived(bool $is_archived): self { $this->is_archived = $is_archived; return $this; }
    public function isPinned(): bool { return $this->is_pinned; }
    public function setIsPinned(bool $is_pinned): self { $this->is_pinned = $is_pinned; return $this; }
}