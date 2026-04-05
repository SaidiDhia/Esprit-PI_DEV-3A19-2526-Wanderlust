<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "message")]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(type: "bigint")]
    private ?int $conversation_id = null;

    #[ORM\Column(type: "string", length: 36)]
    private ?string $sender_id = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: "string", length: 20, options: ["default" => "TEXT"])]
    private ?string $message_type = 'TEXT';

    #[ORM\Column(type: "string", length: 500, nullable: true)]
    private ?string $file_url = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $file_name = null;

    #[ORM\Column(type: "bigint", nullable: true)]
    private ?int $file_size = null;

    #[ORM\Column(type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $created_at = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTimeInterface $edited_at = null;

    // Getters and setters...
    public function getId(): ?int { return $this->id; }
    public function getConversationId(): ?int { return $this->conversation_id; }
    public function setConversationId(int $conversation_id): self { $this->conversation_id = $conversation_id; return $this; }
    public function getSenderId(): ?string { return $this->sender_id; }
    public function setSenderId(string $sender_id): self { $this->sender_id = $sender_id; return $this; }
    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): self { $this->content = $content; return $this; }
    public function getMessageType(): ?string { return $this->message_type; }
    public function setMessageType(string $message_type): self { $this->message_type = $message_type; return $this; }
    public function getFileUrl(): ?string { return $this->file_url; }
    public function setFileUrl(?string $file_url): self { $this->file_url = $file_url; return $this; }
    public function getFileName(): ?string { return $this->file_name; }
    public function setFileName(?string $file_name): self { $this->file_name = $file_name; return $this; }
    public function getFileSize(): ?int { return $this->file_size; }
    public function setFileSize(?int $file_size): self { $this->file_size = $file_size; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $created_at): self { $this->created_at = $created_at; return $this; }
    public function getEditedAt(): ?\DateTimeInterface { return $this->edited_at; }
    public function setEditedAt(?\DateTimeInterface $edited_at): self { $this->edited_at = $edited_at; return $this; }
}