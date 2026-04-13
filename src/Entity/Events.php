<?php


namespace App\Entity;

use App\Repository\EventsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventsRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
class Events
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private ?string $lieu = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $dateDebut;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $dateFin;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateLimiteInscription = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $prix = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $capaciteMax = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $placesDisponibles = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $organisateur = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $materielsNecessaires = null;

    #[ORM\OneToMany(targetEntity: EventImages::class, mappedBy: 'event', cascade: ['persist', 'remove'])]
    private Collection $images;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private ?string $telephone = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $video = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private ?string $statut = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateModification = null;

    #[ORM\ManyToMany(targetEntity: Activities::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'events_activities')]
    private Collection $activities;

    public function __construct()
    {
        $this->activities = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->dateCreation = new \DateTime();
        $this->dateDebut = new \DateTime(); // Valeur par défaut
        $this->dateFin = new \DateTime('+1 day'); // Valeur par défaut
    }

    // --- Lifecycle Callbacks ---

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->dateModification = new \DateTime();
    }

    // --- Getters & Setters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(string $lieu): static
    {
        $this->lieu = $lieu;
        return $this;
    }

    public function getDateDebut(): \DateTimeInterface
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeInterface $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): \DateTimeInterface
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeInterface $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function getDateLimiteInscription(): ?\DateTimeInterface
    {
        return $this->dateLimiteInscription;
    }

    public function setDateLimiteInscription(?\DateTimeInterface $dateLimiteInscription): static
    {
        $this->dateLimiteInscription = $dateLimiteInscription;
        return $this;
    }

    public function getPrix(): ?string
    {
        return $this->prix;
    }

    public function setPrix(string $prix): static
    {
        $this->prix = $prix;
        return $this;
    }

    public function getCapaciteMax(): ?int
    {
        return $this->capaciteMax;
    }

    public function setCapaciteMax(int $capaciteMax): static
    {
        $this->capaciteMax = $capaciteMax;
        return $this;
    }

    public function getPlacesDisponibles(): ?int
    {
        return $this->placesDisponibles;
    }

    public function setPlacesDisponibles(int $placesDisponibles): static
    {
        $this->placesDisponibles = $placesDisponibles;
        return $this;
    }

    public function getOrganisateur(): ?string
    {
        return $this->organisateur;
    }

    public function setOrganisateur(string $organisateur): static
    {
        $this->organisateur = $organisateur;
        return $this;
    }

    public function getMaterielsNecessaires(): ?string
    {
        return $this->materielsNecessaires;
    }

    public function setMaterielsNecessaires(string $materielsNecessaires): static
    {
        $this->materielsNecessaires = $materielsNecessaires;
        return $this;
    }

    public function getImages(): Collection
    {
        return $this->images;
    }
    public function getEventImages(): Collection
{
    return $this->eventImages;
}
    public function addImage(EventImages $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setEvent($this);
        }

        return $this;
    }

    public function removeImage(EventImages $image): static
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getEvent() === $this) {
                $image->setEvent(null);
            }
        }

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getVideo(): ?string
    {
        return $this->video;
    }

    public function setVideo(?string $video): static
    {
        $this->video = $video;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeInterface $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getDateModification(): ?\DateTimeInterface
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeInterface $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }

    // --- Activities ---

    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(Activities $activity): static
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
            $activity->addEvent($this); // Synchroniser la relation inverse
        }
        return $this;
    }

    public function removeActivity(Activities $activity): static
    {
        if ($this->activities->removeElement($activity)) {
            $activity->removeEvent($this); // Synchroniser la relation inverse
        }
        return $this;
    }
    
}
