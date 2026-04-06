<?php

namespace App\Entity;

use App\Repository\ReservationsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationsRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'reservations')]
class Reservations
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_CONFIRMEE = 'confirmee';
    public const STATUT_ANNULEE = 'annulee';
    public const STATUT_TERMINEE = 'terminee';
    
    public const METHODE_PAIEMENT_CARTE = 'carte';
    public const METHODE_PAIEMENT_ESPECE = 'espece';
    public const METHODE_PAIEMENT_VIREMENT = 'virement';
    public const METHODE_PAIEMENT_PAYPAL = 'paypal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'id_event', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'événement est obligatoire')]
    private ?Events $event = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom complet est obligatoire')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le nom doit contenir au moins {{ limit }} caractères', maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^[a-zA-ZÀ-ÿ\s\-\'\.]+$/', message: 'Le nom ne peut contenir que des lettres, espaces, tirets, apostrophes et points')]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire')]
    #[Assert\Email(message: 'Veuillez entrer un email valide')]
    #[Assert\Length(max: 100, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères')]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^(\+[0-9]{1,3})?[0-9\s\.\-\(\)]{10,20}$/', message: 'Numéro de téléphone invalide')]
    #[Assert\Length(max: 20, maxMessage: 'Le téléphone ne peut pas dépasser {{ limit }} caractères')]
    private ?string $telephone = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le nombre de personnes est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de personnes doit être un nombre positif')]
    #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'Le nombre de personnes doit être compris entre {{ min }} et {{ max }}')]
    private ?int $nombrePersonnes = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix total est obligatoire')]
    #[Assert\Positive(message: 'Le prix total doit être un nombre positif')]
    private ?string $prixTotal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'Les demandes spéciales ne peuvent pas dépasser {{ limit }} caractères')]
    private ?string $demandesSpeciales = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUT_EN_ATTENTE, self::STATUT_CONFIRMEE, self::STATUT_ANNULEE, self::STATUT_TERMINEE], message: 'Veuillez choisir un statut valide')]
    private ?string $statut = self::STATUT_EN_ATTENTE;

    #[ORM\Column(name: 'date_creation', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateCreation = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTime();
        $this->statut = self::STATUT_EN_ATTENTE;
    }

    /**
     * Calculer automatiquement le prix total basé sur l'événement et le nombre de personnes
     */
    public function calculerPrixTotal(): void
    {
        if ($this->event && $this->nombrePersonnes) {
            // Récupérer le prix de l'événement (supposons qu'il y ait un champ prix dans Events)
            $prixUnitaire = $this->event->getPrix() ?? 0;
            $this->prixTotal = $prixUnitaire * $this->nombrePersonnes;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Events
    {
        return $this->event;
    }

    public function setEvent(?Events $event): static
    {
        $this->event = $event;
        return $this;
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;
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

    public function getTelephone(): ?int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getNombrePersonnes(): ?int
    {
        return $this->nombrePersonnes;
    }

    public function setNombrePersonnes(int $nombrePersonnes): static
    {
        $this->nombrePersonnes = $nombrePersonnes;
        return $this;
    }

    public function getPrixTotal(): ?string
    {
        return $this->prixTotal;
    }

    public function setPrixTotal(string $prixTotal): static
    {
        $this->prixTotal = $prixTotal;
        return $this;
    }

    public function getDemandesSpeciales(): ?string
    {
        return $this->demandesSpeciales;
    }

    public function setDemandesSpeciales(?string $demandesSpeciales): static
    {
        $this->demandesSpeciales = $demandesSpeciales;
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

    public function setDateCreation(\DateTimeInterface $dateCreation): static
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

    /**
     * Méthodes utilitaires
     */
    public function getResteAPayer(): ?string
    {
        if ($this->montantPaye === null) {
            return $this->prixTotal;
        }
        
        $reste = bcsub($this->prixTotal, $this->montantPaye, 2);
        return $reste > 0 ? $reste : '0.00';
    }

    public function isPayeComplet(): bool
    {
        if ($this->montantPaye === null) {
            return false;
        }
        
        return bccomp($this->montantPaye, $this->prixTotal, 2) >= 0;
    }

    public function getStatutLabel(): string
    {
        return match($this->statut) {
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_CONFIRMEE => 'Confirmée',
            self::STATUT_ANNULEE => 'Annulée',
            self::STATUT_TERMINEE => 'Terminée',
            default => 'Inconnu'
        };
    }

    public function getMethodePaiementLabel(): string
    {
        return match($this->methodePaiement) {
            self::METHODE_PAIEMENT_CARTE => 'Carte bancaire',
            self::METHODE_PAIEMENT_ESPECE => 'Espèces',
            self::METHODE_PAIEMENT_VIREMENT => 'Virement bancaire',
            self::METHODE_PAIEMENT_PAYPAL => 'PayPal',
            default => 'Non spécifié'
        };
    }

    public function getStatutBadgeClass(): string
    {
        return match($this->statut) {
            self::STATUT_EN_ATTENTE => 'bg-warning',
            self::STATUT_CONFIRMEE => 'bg-success',
            self::STATUT_ANNULEE => 'bg-danger',
            self::STATUT_TERMINEE => 'bg-info',
            default => 'bg-secondary'
        };
    }

    /**
     * Lifecycle callbacks
     */
    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->dateModification = new \DateTime();
    }

    /**
     * Validation personnalisée
     */
    public function canBeConfirmed(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->statut, [self::STATUT_EN_ATTENTE, self::STATUT_CONFIRMEE]);
    }

    public function canBeCompleted(): bool
    {
        return $this->statut === self::STATUT_CONFIRMEE;
    }

    /**
     * Getters pour les constantes
     */
    public static function getStatuts(): array
    {
        return [
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_CONFIRMEE => 'Confirmée',
            self::STATUT_ANNULEE => 'Annulée',
            self::STATUT_TERMINEE => 'Terminée'
        ];
    }

    public static function getMethodesPaiement(): array
    {
        return [
            self::METHODE_PAIEMENT_CARTE => 'Carte bancaire',
            self::METHODE_PAIEMENT_ESPECE => 'Espèces',
            self::METHODE_PAIEMENT_VIREMENT => 'Virement bancaire',
            self::METHODE_PAIEMENT_PAYPAL => 'PayPal'
        ];
    }
}
