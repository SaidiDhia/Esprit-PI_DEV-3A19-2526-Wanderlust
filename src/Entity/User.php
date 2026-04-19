<?php

namespace App\Entity;

use App\Enum\RoleEnum;
use App\Enum\TFAMethod;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface as GoogleTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: "users")]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, GoogleTwoFactorInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, options: ['fixed' => true])]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $password = null;

    #[ORM\Column(name: 'full_name', type: 'string', length: 255, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(name: 'phone_number', type: 'string', length: 30, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(name: 'profile_picture', type: 'string', length: 255, nullable: true)]
    private ?string $profilePicture = null;

    #[ORM\Column(type: 'string', enumType: RoleEnum::class, options: ['default' => 'PARTICIPANT'])]
    private RoleEnum $role = RoleEnum::PARTICIPANT;

    #[ORM\Column(name: 'tfa_method', type: 'string', enumType: TFAMethod::class, options: ['default' => 'NONE'])]
    private TFAMethod $tfaMethod = TFAMethod::NONE;

    #[ORM\Column(name: 'tfa_secret', type: 'string', length: 64, nullable: true)]
    private ?string $tfaSecret = null;

    #[ORM\Column(name: 'face_reference_image', type: 'string', length: 255, nullable: true)]
    private ?string $faceReferenceImage = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Posts>
     */
    #[ORM\OneToMany(targetEntity: Posts::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $posts;

    /**
     * @var Collection<int, Commentaires>
     */
    #[ORM\OneToMany(targetEntity: Commentaires::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $commentaires;

    /**
     * @var Collection<int, Reactions>
     */
    #[ORM\OneToMany(targetEntity: Reactions::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $reactions;

    /**
     * @var Collection<int, PostsSauvegardes>
     */
    #[ORM\OneToMany(targetEntity: PostsSauvegardes::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $savedPosts;

    public function __construct()
    {
        $this->id = $this->generateUuidV4();
        $this->createdAt = new \DateTimeImmutable();
        $this->posts = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->reactions = new ArrayCollection();
        $this->savedPosts = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new \InvalidArgumentException('User ID must be a valid UUID v4.');
        }

        $this->id = $id;

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_' . $this->role->value];
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): self
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getProfilePicture(): ?string
    {
        return $this->profilePicture;
    }

    public function setProfilePicture(?string $profilePicture): self
    {
        $this->profilePicture = $profilePicture;

        return $this;
    }

    public function getRole(): RoleEnum
    {
        return $this->role;
    }

    public function setRole(RoleEnum $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getRoleValue(): string
    {
        return $this->role->value;
    }

    public function getRoleLabel(): string
    {
        return $this->role->getLabel();
    }

    public function isAdmin(): bool
    {
        return $this->role === RoleEnum::ADMIN;
    }

    public function getTfaMethod(): TFAMethod
    {
        return $this->tfaMethod;
    }

    public function setTfaMethod(TFAMethod $tfaMethod): self
    {
        $this->tfaMethod = $tfaMethod;

        return $this;
    }

    public function getTfaValue(): string
    {
        return $this->tfaMethod->value;
    }

    public function getTfaLabel(): string
    {
        return $this->tfaMethod->getLabel();
    }

    public function getTfaSecret(): ?string
    {
        return $this->tfaSecret;
    }

    public function setTfaSecret(?string $tfaSecret): self
    {
        $this->tfaSecret = $tfaSecret;

        return $this;
    }

    public function isGoogleAuthenticatorEnabled(): bool
    {
        return $this->tfaMethod === TFAMethod::APP && !empty($this->tfaSecret);
    }

    public function getGoogleAuthenticatorUsername(): string
    {
        return (string) $this->email;
    }

    public function getGoogleAuthenticatorSecret(): ?string
    {
        return $this->tfaSecret;
    }

    public function getFaceReferenceImage(): ?string
    {
        return $this->faceReferenceImage;
    }

    public function setFaceReferenceImage(?string $faceReferenceImage): self
    {
        $this->faceReferenceImage = $faceReferenceImage;

        return $this;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, Posts>
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    public function addPost(Posts $post): self
    {
        if (!$this->posts->contains($post)) {
            $this->posts->add($post);
            $post->setUser($this);
        }
        return $this;
    }

    public function removePost(Posts $post): self
    {
        if ($this->posts->removeElement($post)) {
            if ($post->getUser() === $this) {
                $post->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Commentaires>
     */
    public function getCommentaires(): Collection
    {
        return $this->commentaires;
    }

    public function addCommentaire(Commentaires $commentaire): self
    {
        if (!$this->commentaires->contains($commentaire)) {
            $this->commentaires->add($commentaire);
            $commentaire->setUser($this);
        }
        return $this;
    }

    public function removeCommentaire(Commentaires $commentaire): self
    {
        if ($this->commentaires->removeElement($commentaire)) {
            if ($commentaire->getUser() === $this) {
                $commentaire->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Reactions>
     */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    public function addReaction(Reactions $reaction): self
    {
        if (!$this->reactions->contains($reaction)) {
            $this->reactions->add($reaction);
            $reaction->setUser($this);
        }
        return $this;
    }

    public function removeReaction(Reactions $reaction): self
    {
        if ($this->reactions->removeElement($reaction)) {
            if ($reaction->getUser() === $this) {
                $reaction->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PostsSauvegardes>
     */
    public function getSavedPosts(): Collection
    {
        return $this->savedPosts;
    }

    public function addSavedPost(PostsSauvegardes $savedPost): self
    {
        if (!$this->savedPosts->contains($savedPost)) {
            $this->savedPosts->add($savedPost);
            $savedPost->setUser($this);
        }
        return $this;
    }

    public function removeSavedPost(PostsSauvegardes $savedPost): self
    {
        if ($this->savedPosts->removeElement($savedPost)) {
            if ($savedPost->getUser() === $this) {
                $savedPost->setUser(null);
            }
        }
        return $this;
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}