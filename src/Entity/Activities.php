<?php

namespace App\Entity;

use App\Enum\CategorieActiviteEnum;
use App\Enum\StatusActiviteEnum;
use App\Repository\ActivitiesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivitiesRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'activites')]
class Activities
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToMany(targetEntity: Events::class, mappedBy: 'activities')]
    private Collection $events;

    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 50, enumType: CategorieActiviteEnum::class)]
    private ?CategorieActiviteEnum $categorie = null;

    #[ORM\Column(length: 100)]
    private ?string $type_activite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $date_modification = null;

    #[ORM\Column(type: 'string', length: 50, enumType: StatusActiviteEnum::class, options: ['default' => 'en_attente'])]
    private ?StatusActiviteEnum $status = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ageMinimum = null;

    public function __construct()
    {
        $this->events = new ArrayCollection();
        $this->date_creation = new \DateTime();
        $this->status = StatusActiviteEnum::EN_ATTENTE;  // Valeur par défaut
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Events>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(Events $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            // Pas besoin d'appeler la méthode inverse car Events::addActivity() le fait déjà
        }

        return $this;
    }

    public function removeEvent(Events $event): static
    {
        if ($this->events->removeElement($event)) {
            $event->removeActivity($this); // Synchroniser la relation inverse
        }

        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategorie(): ?CategorieActiviteEnum
    {
        return $this->categorie;
    }

    public function setCategorie(CategorieActiviteEnum $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getTypeActivite(): ?string
    {
        return $this->type_activite;
    }

    public function setTypeActivite(string $type_activite): static
    {
        $this->type_activite = $type_activite;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTimeInterface $date_creation): static
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    public function getDateModification(): ?\DateTimeInterface
    {
        return $this->date_modification;
    }

    public function setDateModification(?\DateTimeInterface $date_modification): static
    {
        $this->date_modification = $date_modification;
        return $this;
    }

    // ✅ AJOUT : Getter et Setter pour status
    public function getStatus(): ?StatusActiviteEnum
    {
        return $this->status;
    }

    public function setStatus(StatusActiviteEnum $status): static
    {
        $this->status = $status;
        return $this;
    }

    // ✅ AJOUT : Getter et Setter pour ageMinimum
    public function getAgeMinimum(): ?int
    {
        return $this->ageMinimum;
    }

    public function setAgeMinimum(?int $ageMinimum): static
    {
        $this->ageMinimum = $ageMinimum;
        return $this;
    }

    // ✅ AJOUT : Méthodes pratiques pour vérifier le status
    public function isAccepted(): bool
    {
        return $this->status === StatusActiviteEnum::ACCEPTE;
    }

    public function isRefused(): bool
    {
        return $this->status === StatusActiviteEnum::REFUSE;
    }

    public function isPending(): bool
    {
        return $this->status === StatusActiviteEnum::EN_ATTENTE;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->date_creation = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->date_modification = new \DateTime();
    }
}
