<?php

namespace App\Entity;

use App\Repository\EventsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventsRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'events')]
class Events
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_ACCEPTE = 'accepte';
    public const STATUT_REFUSE = 'refuse';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToMany(targetEntity: Activities::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'events_activities')]
    private Collection $activities;

    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Reservations::class, cascade: ['remove'])]
    private Collection $reservations;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le lieu est obligatoire')]
    #[Assert\Length(min: 3, max: 150, minMessage: 'Le lieu doit contenir au moins {{ limit }} caractères', maxMessage: 'Le lieu ne peut pas dépasser {{ limit }} caractères')]
    private ?string $lieu = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotBlank(message: 'La date et heure de début sont obligatoires')]
    #[Assert\Type(\DateTime::class, message: 'La date de début doit être une date valide')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date de début ne peut pas être antérieure à aujourd\'hui')]
    private ?\DateTime $date_debut = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotBlank(message: 'La date et heure de fin sont obligatoires')]
    #[Assert\Type(\DateTime::class, message: 'La date de fin doit être une date valide')]
    #[Assert\GreaterThan(propertyPath: 'date_debut', message: 'La date de fin doit être postérieure à la date de début')]
    private ?\DateTime $date_fin = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['en_attente', 'accepte', 'refuse'], message: 'Veuillez choisir un statut valide')]
    private string $statut = 'en_attente';

    #[ORM\Column(type: 'boolean')]
    #[Assert\IsTrue(message: 'Vous devez confirmer être l\'organisateur de cet événement')]
    private bool $confirmation_organisateur = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix est obligatoire')]
    #[Assert\Positive(message: 'Le prix doit être un nombre positif')]
    private ?string $prix = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'La capacité maximale est obligatoire')]
    #[Assert\Positive(message: 'La capacité maximale doit être un nombre positif')]
    #[Assert\Range(min: 1, max: 10000, notInRangeMessage: 'La capacité doit être comprise entre {{ min }} et {{ max }}')]
    private ?int $capacite_max = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le nombre de places disponibles est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de places disponibles doit être un nombre positif')]
    #[Assert\LessThanOrEqual(propertyPath: 'capacite_max', message: 'Les places disponibles ne peuvent pas dépasser la capacité maximale')]
    private ?int $places_disponibles = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'L\'organisateur est obligatoire')]
    #[Assert\Length(min: 2, max: 255, minMessage: 'Le nom de l\'organisateur doit contenir au moins {{ limit }} caractères', maxMessage: 'Le nom de l\'organisateur ne peut pas dépasser {{ limit }} caractères')]
    private ?string $organisateur = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description des matériels est obligatoire')]
    #[Assert\Length(min: 10, minMessage: 'La description des matériels doit contenir au moins {{ limit }} caractères')]
    private ?string $materiels_necessaires = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Le nom du fichier image ne peut pas dépasser {{ limit }} caractères')]
    private ?string $image = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire')]
    #[Assert\Type(type: 'integer', message: 'Le numéro de téléphone doit être un nombre')]
    #[Assert\Length(min: 10, max: 20, minMessage: 'Le numéro de téléphone doit contenir au moins {{ limit }} chiffres', maxMessage: 'Le numéro de téléphone ne peut pas dépasser {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^[0-9\s\-\+\(\)]+$/', message: 'Le numéro de téléphone ne peut contenir que des chiffres, espaces, tirets, plus et parenthèses')]
    private ?int $telephone = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire')]
    #[Assert\Email(message: 'Veuillez entrer une adresse email valide')]
    #[Assert\Length(max: 255, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères')]
    private ?string $email = null;

    #[ORM\Column(length: 500)]
    #[Assert\Length(max: 500, maxMessage: 'L\'URL YouTube ne peut pas dépasser {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^(https?:\/\/)?(www\.)?(youtube\.com\/watch\?v=[\w-]{11}|youtu\.be\/[\w-]{11}|youtube\.com\/embed\/[\w-]{11})(?:\S*)?$/', message: 'Veuillez entrer une URL YouTube valide')]
    private ?string $video_youtube = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $date_creation = null;

    public function __construct()
    {
        $this->activities = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->date_creation = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Activities>
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(Activities $activity): static
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
        }

        return $this;
    }

    public function removeActivity(Activities $activity): static
    {
        $this->activities->removeElement($activity);

        return $this;
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

    public function getDateDebut(): ?\DateTime
    {
        return $this->date_debut;
    }

    public function setDateDebut(\DateTime $date_debut): static
    {
        $this->date_debut = $date_debut;

        return $this;
    }

    public function getDateFin(): ?\DateTime
    {
        return $this->date_fin;
    }

    public function setDateFin(\DateTime $date_fin): static
    {
        $this->date_fin = $date_fin;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        if (!in_array($statut, [self::STATUT_EN_ATTENTE, self::STATUT_ACCEPTE, self::STATUT_REFUSE])) {
            throw new \InvalidArgumentException('Statut invalide');
        }

        $this->statut = $statut;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return match($this->statut) {
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_ACCEPTE => 'Accepté',
            self::STATUT_REFUSE => 'Refusé',
            default => 'Inconnu'
        };
    }

    public function getStatutBadgeClass(): string
    {
        return match($this->statut) {
            self::STATUT_EN_ATTENTE => 'warning',
            self::STATUT_ACCEPTE => 'success',
            self::STATUT_REFUSE => 'danger',
            default => 'secondary'
        };
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
        return $this->capacite_max;
    }

    public function setCapaciteMax(int $capacite_max): static
    {
        $this->capacite_max = $capacite_max;

        return $this;
    }

    public function getPlacesDisponibles(): ?int
    {
        return $this->places_disponibles;
    }

    public function setPlacesDisponibles(int $places_disponibles): static
    {
        $this->places_disponibles = $places_disponibles;

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
        return $this->materiels_necessaires;
    }

    public function setMaterielsNecessaires(string $materiels_necessaires): static
    {
        $this->materiels_necessaires = $materiels_necessaires;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getTelephone(): ?int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): static
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

    public function getVideoYoutube(): ?string
    {
        return $this->video_youtube;
    }

    public function setVideoYoutube(string $video_youtube): static
    {
        $this->video_youtube = $video_youtube;

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

    public function isConfirmationOrganisateur(): bool
    {
        return $this->confirmation_organisateur;
    }

    public function setConfirmationOrganisateur(bool $confirmation_organisateur): static
    {
        $this->confirmation_organisateur = $confirmation_organisateur;

        return $this;
    }

    /**
     * @return Collection<int, Reservations>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservations $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setEvent($this);
        }

        return $this;
    }

    public function removeReservation(Reservations $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getEvent() === $this) {
                $reservation->setEvent(null);
            }
        }

        return $this;
    }

    /**
     * Calculer le nombre total de places réservées
     */
    public function getTotalPlacesReservees(): int
    {
        $total = 0;
        foreach ($this->reservations as $reservation) {
            if ($reservation->getStatut() !== Reservations::STATUT_ANNULEE) {
                $total += $reservation->getNombrePersonnes();
            }
        }
        return $total;
    }

    /**
     * Vérifier si des places sont disponibles
     */
    public function hasAvailablePlaces(int $nombrePersonnes = 1): bool
    {
        return $this->getPlacesDisponibles() >= $this->getTotalPlacesReservees() + $nombrePersonnes;
    }

    /**
     * Obtenir le nombre de places restantes
     */
    public function getPlacesRestantes(): int
    {
        $reservees = $this->getTotalPlacesReservees();
        return max(0, $this->getPlacesDisponibles() - $reservees);
    }
}
