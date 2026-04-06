<?php

namespace App\Entity;

use App\Repository\CommentairesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commentaires')]
class Commentaires
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_commentaire', type: 'integer')]
    private ?int $idCommentaire = null;

    #[ORM\Column(name: 'contenu', type: 'text')]
    private ?string $contenu = null;

    #[ORM\Column(name: 'date', type: 'datetime', nullable: true, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: Posts::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(name: 'id_post', referencedColumnName: 'id_post', nullable: false, onDelete: 'CASCADE')]
    private ?Posts $post = null;

    /**
     * Relation with User (author of the comment)
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'id_parent', referencedColumnName: 'id_commentaire', onDelete: 'CASCADE')]
    private ?self $parent = null;

    /**
     * @var Collection<int, Commentaires>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $replies;

    /**
     * @var Collection<int, Reactions>
     */
    #[ORM\OneToMany(targetEntity: Reactions::class, mappedBy: 'commentaire', cascade: ['remove'])]
    private Collection $reactions;

    public function __construct()
    {
        $this->replies = new ArrayCollection();
        $this->reactions = new ArrayCollection();
    }

    // Getters and setters

    public function getIdCommentaire(): ?int { return $this->idCommentaire; }

    public function getContenu(): ?string { return $this->contenu; }
    public function setContenu(string $contenu): self { $this->contenu = $contenu; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    public function getPost(): ?Posts { return $this->post; }
    public function setPost(?Posts $post): self { $this->post = $post; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getParent(): ?self { return $this->parent; }
    public function setParent(?self $parent): self { $this->parent = $parent; return $this; }

    public function getReplies(): Collection { return $this->replies; }
    public function addReply(self $reply): self { if (!$this->replies->contains($reply)) { $this->replies->add($reply); $reply->setParent($this); } return $this; }
    public function removeReply(self $reply): self { $this->replies->removeElement($reply); return $this; }

    public function getReactions(): Collection { return $this->reactions; }
    public function addReaction(Reactions $reaction): self { if (!$this->reactions->contains($reaction)) { $this->reactions->add($reaction); $reaction->setCommentaire($this); } return $this; }
    public function removeReaction(Reactions $reaction): self { $this->reactions->removeElement($reaction); return $this; }
}