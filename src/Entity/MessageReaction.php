<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "message_reactions")]
class MessageReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(type: "bigint")]
    private ?int $message_id = null;

    #[ORM\Column(type: "string", length: 36)]
    private ?string $user_id = null;

    #[ORM\Column(type: "string", length: 10)]
    private ?string $reaction = null;

    #[ORM\Column(type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $created_at = null;

    // Getters and setters
    public function getId(): ?int { return $this->id; }
    public function getMessageId(): ?int { return $this->message_id; }
    public function setMessageId(int $message_id): self { $this->message_id = $message_id; return $this; }
    public function getUserId(): ?string { return $this->user_id; }
    public function setUserId(string $user_id): self { $this->user_id = $user_id; return $this; }
    public function getReaction(): ?string { return $this->reaction; }
    public function setReaction(string $reaction): self { $this->reaction = $reaction; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $created_at): self { $this->created_at = $created_at; return $this; }
}
