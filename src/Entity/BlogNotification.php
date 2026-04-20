<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'blog_notifications')]
class BlogNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'recipient_user_id', type: 'string', length: 36)]
    private string $recipientUserId;

    #[ORM\Column(name: 'actor_username', type: 'string', length: 150)]
    private string $actorUsername;

    #[ORM\Column(name: 'type', type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(name: 'post_preview', type: 'text', nullable: true)]
    private ?string $postPreview = null;

    #[ORM\Column(name: 'content_preview', type: 'text', nullable: true)]
    private ?string $contentPreview = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'is_read', type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    public function getId(): ?int { return $this->id; }

    public function getRecipientUserId(): string { return $this->recipientUserId; }
    public function setRecipientUserId(string $v): self { $this->recipientUserId = $v; return $this; }

    public function getActorUsername(): string { return $this->actorUsername; }
    public function setActorUsername(string $v): self { $this->actorUsername = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): self { $this->type = $v; return $this; }

    public function getPostPreview(): ?string { return $this->postPreview; }
    public function setPostPreview(?string $v): self { $this->postPreview = $v; return $this; }

    public function getContentPreview(): ?string { return $this->contentPreview; }
    public function setContentPreview(?string $v): self { $this->contentPreview = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): self { $this->createdAt = $v; return $this; }

    public function isRead(): bool { return $this->isRead; }
    public function getIsRead(): bool { return $this->isRead; }
    public function setIsRead(bool $v): self { $this->isRead = $v; return $this; }
}