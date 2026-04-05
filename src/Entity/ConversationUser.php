<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "conversation_user")]
class ConversationUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(type: "bigint")]
    private ?int $conversation_id = null;

    #[ORM\Column(type: "string", length: 36)]
    private ?string $user_id = null;

    #[ORM\Column(type: "string", length: 20, options: ["default" => "MEMBER"])]
    private ?string $role = 'MEMBER';

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $is_active = true;

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getConversationId(): ?int { return $this->conversation_id; }
    public function setConversationId(int $conversation_id): self { $this->conversation_id = $conversation_id; return $this; }
    public function getUserId(): ?string { return $this->user_id; }
    public function setUserId(string $user_id): self { $this->user_id = $user_id; return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(string $role): self { $this->role = $role; return $this; }
    public function isActive(): bool { return $this->is_active; }
    public function setIsActive(bool $is_active): self { $this->is_active = $is_active; return $this; }
}