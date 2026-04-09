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

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    /**
     * @var Collection<int, Commentaires>
     */
    #[ORM\OneToMany(targetEntity: Commentaires::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $commentaires;

    /**
     * @var Collection<int, Reactions>
     */
    #[ORM\OneToMany(targetEntity: Reactions::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $reactions;

    /**
     * @var Collection<int, PostsSauvegardes>
     */
    #[ORM\OneToMany(targetEntity: PostsSauvegardes::class, mappedBy: 'post', cascade: ['remove'])]
    private Collection $savedByUsers;

    public function __construct()
    {
        $this->commentaires = new ArrayCollection();
        $this->reactions = new ArrayCollection();
        $this->savedByUsers = new ArrayCollection();
    }

    public function getIdPost(): ?int
    {
        return $this->idPost;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getMedia(): ?string
    {
        return $this->media;
    }

    public function setMedia(?string $media): self
    {
        $this->media = $media;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeInterface $dateCreation): self
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCommentaires(): Collection
    {
        return $this->commentaires;
    }

    public function addCommentaire(Commentaires $commentaire): self
    {
        if (!$this->commentaires->contains($commentaire)) {
            $this->commentaires->add($commentaire);
            $commentaire->setPost($this);
        }

        return $this;
    }

    public function removeCommentaire(Commentaires $commentaire): self
    {
        $this->commentaires->removeElement($commentaire);
        return $this;
    }

    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    public function addReaction(Reactions $reaction): self
    {
        if (!$this->reactions->contains($reaction)) {
            $this->reactions->add($reaction);
            $reaction->setPost($this);
        }

        return $this;
    }

    public function removeReaction(Reactions $reaction): self
    {
        $this->reactions->removeElement($reaction);
        return $this;
    }

    public function getSavedByUsers(): Collection
    {
        return $this->savedByUsers;
    }

    public function addSavedByUser(PostsSauvegardes $save): self
    {
        if (!$this->savedByUsers->contains($save)) {
            $this->savedByUsers->add($save);
            $save->setPost($this);
        }

        return $this;
    }

    public function removeSavedByUser(PostsSauvegardes $save): self
    {
        $this->savedByUsers->removeElement($save);
        return $this;
    }
}
