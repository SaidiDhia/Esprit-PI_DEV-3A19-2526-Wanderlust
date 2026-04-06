<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reactions')]
class Reactions
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_reaction', type: 'integer')]
    private ?int $idReaction = null;

    // ✅ FIXED: removed enumType — type is just a plain string ('LIKE', 'LOVE', etc.)
    #[ORM\Column(name: 'type', type: 'string', length: 255)]
    private ?string $type = null;

    #[ORM\Column(name: 'date', type: 'datetime', nullable: true, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: Posts::class, inversedBy: 'reactions')]
    #[ORM\JoinColumn(name: 'id_post', referencedColumnName: 'id_post', nullable: true, onDelete: 'CASCADE')]
    private ?Posts $post = null;

    #[ORM\ManyToOne(targetEntity: Commentaires::class, inversedBy: 'reactions')]
    #[ORM\JoinColumn(name: 'id_commentaire', referencedColumnName: 'id_commentaire', nullable: true, onDelete: 'CASCADE')]
    private ?Commentaires $commentaire = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reactions')]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function getIdReaction(): ?int { return $this->idReaction; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    public function getPost(): ?Posts { return $this->post; }
    public function setPost(?Posts $post): self { $this->post = $post; return $this; }

    public function getCommentaire(): ?Commentaires { return $this->commentaire; }
    public function setCommentaire(?Commentaires $commentaire): self { $this->commentaire = $commentaire; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
}