<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'posts')]
class Posts
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_post', type: 'integer')]
    private ?int $idPost = null;

    #[ORM\Column(name: 'contenu', type: 'text')]
    private ?string $contenu = null;

    #[ORM\Column(name: 'media', type: 'string', length: 500, nullable: true)]
    private ?string $media = null;

    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: true, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(name: 'statut', type: 'string', length: 50, nullable: true, options: ['default' => 'public'])]
    private ?string $statut = null;

    // ✅ NEW: scheduled publishing — null = publish immediately
    #[ORM\Column(name: 'scheduled_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    /** @var Collection<int, Commentaires> */
    #[ORM\OneToMany(targetEntity: Commentaires::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $commentaires;

    /** @var Collection<int, Reactions> */
    #[ORM\OneToMany(targetEntity: Reactions::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $reactions;

    /** @var Collection<int, PostsSauvegardes> */
    #[ORM\OneToMany(targetEntity: PostsSauvegardes::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $savedByUsers;

    public function __construct()
    {
        $this->commentaires = new ArrayCollection();
        $this->reactions    = new ArrayCollection();
        $this->savedByUsers = new ArrayCollection();
    }

    public function getIdPost(): ?int { return $this->idPost; }

    public function getContenu(): ?string { return $this->contenu; }
    public function setContenu(string $contenu): self { $this->contenu = $contenu; return $this; }

    public function getMedia(): ?string { return $this->media; }
    public function setMedia(?string $media): self { $this->media = $media; return $this; }

    public function getDateCreation(): ?\DateTimeInterface { return $this->dateCreation; }
    public function setDateCreation(?\DateTimeInterface $d): self { $this->dateCreation = $d; return $this; }

    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(?string $statut): self { $this->statut = $statut; return $this; }

    public function getScheduledAt(): ?\DateTimeInterface { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeInterface $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getCommentaires(): Collection { return $this->commentaires; }
    public function addCommentaire(Commentaires $c): self
    {
        if (!$this->commentaires->contains($c)) { $this->commentaires->add($c); $c->setPost($this); }
        return $this;
    }
    public function removeCommentaire(Commentaires $c): self { $this->commentaires->removeElement($c); return $this; }

    public function getReactions(): Collection { return $this->reactions; }
    public function addReaction(Reactions $r): self
    {
        if (!$this->reactions->contains($r)) { $this->reactions->add($r); $r->setPost($this); }
        return $this;
    }
    public function removeReaction(Reactions $r): self { $this->reactions->removeElement($r); return $this; }

    public function getSavedByUsers(): Collection { return $this->savedByUsers; }
    public function addSavedByUser(PostsSauvegardes $s): self
    {
        if (!$this->savedByUsers->contains($s)) { $this->savedByUsers->add($s); $s->setPost($this); }
        return $this;
    }
    public function removeSavedByUser(PostsSauvegardes $s): self { $this->savedByUsers->removeElement($s); return $this; }
}