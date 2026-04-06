<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @var Collection<int, Posts>
     */
    #[ORM\OneToMany(targetEntity: Posts::class, mappedBy: 'user')]
    private Collection $posts;

    /**
     * @var Collection<int, Commentaires>
     */
    #[ORM\OneToMany(targetEntity: Commentaires::class, mappedBy: 'user')]
    private Collection $commentaires;

    /**
     * @var Collection<int, Reactions>
     */
    #[ORM\OneToMany(targetEntity: Reactions::class, mappedBy: 'user')]
    private Collection $reactions;

    /**
     * @var Collection<int, PostsSauvegardes>
     */
    #[ORM\OneToMany(targetEntity: PostsSauvegardes::class, mappedBy: 'user')]
    private Collection $savedPosts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->reactions = new ArrayCollection();
        $this->savedPosts = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // Getters and setters (keep existing ones + add collection getters)

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getPosts(): Collection { return $this->posts; }
    public function addPost(Posts $post): static { if (!$this->posts->contains($post)) { $this->posts->add($post); $post->setUser($this); } return $this; }
    public function removePost(Posts $post): static { $this->posts->removeElement($post); return $this; }

    public function getCommentaires(): Collection { return $this->commentaires; }
    public function addCommentaire(Commentaires $commentaire): static { if (!$this->commentaires->contains($commentaire)) { $this->commentaires->add($commentaire); $commentaire->setUser($this); } return $this; }
    public function removeCommentaire(Commentaires $commentaire): static { $this->commentaires->removeElement($commentaire); return $this; }

    public function getReactions(): Collection { return $this->reactions; }
    public function addReaction(Reactions $reaction): static { if (!$this->reactions->contains($reaction)) { $this->reactions->add($reaction); $reaction->setUser($this); } return $this; }
    public function removeReaction(Reactions $reaction): static { $this->reactions->removeElement($reaction); return $this; }

    public function getSavedPosts(): Collection { return $this->savedPosts; }
    public function addSavedPost(PostsSauvegardes $save): static { if (!$this->savedPosts->contains($save)) { $this->savedPosts->add($save); $save->setUser($this); } return $this; }
    public function removeSavedPost(PostsSauvegardes $save): static { $this->savedPosts->removeElement($save); return $this; }
}