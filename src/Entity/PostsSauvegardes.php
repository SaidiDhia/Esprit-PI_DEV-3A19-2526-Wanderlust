<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'posts_sauvegardes')]
#[ORM\UniqueConstraint(name: 'idx_unique_save', columns: ['id_user', 'id_post'])]
class PostsSauvegardes
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'savedPosts')]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Posts::class, inversedBy: 'savedByUsers')]
    #[ORM\JoinColumn(name: 'id_post', referencedColumnName: 'id_post', onDelete: 'CASCADE')]
    private ?Posts $post = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPost(): ?Posts
    {
        return $this->post;
    }

    public function setPost(?Posts $post): self
    {
        $this->post = $post;
        return $this;
    }
}
