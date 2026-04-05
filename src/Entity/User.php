<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "users")]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: "string", length: 36)]
    private ?string $user_id = null;

    #[ORM\Column(type: "string", length: 255)]
    private ?string $email = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $full_name = null;

    // Getters and setters
    public function getUserId(): ?string { return $this->user_id; }
    public function setUserId(string $user_id): self { $this->user_id = $user_id; return $this; }
    
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }
    
    public function getFullName(): ?string { return $this->full_name; }
    public function setFullName(?string $full_name): self { $this->full_name = $full_name; return $this; }
}