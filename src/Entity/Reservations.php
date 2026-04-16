<?php

namespace App\Entity;

use App\Repository\ReservationsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationsRepository::class)]
#[ORM\Table(name: 'reservations')]
#[ORM\HasLifecycleCallbacks]
class Reservations
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Events::class)]
    #[ORM\JoinColumn(name: 'id_event', nullable: false, onDelete: 'CASCADE')]
    private ?Events $event = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $nombreAdultes = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $nombreEnfants = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $nombrePersonnes = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $prixTotal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $demandesSpeciales = null;

    #[ORM\Column(length: 20)]
    private string $statut = 'en_attente';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateCreation = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTime();
    }

    // ── Getters & Setters ──────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getEvent(): ?Events { return $this->event; }
    public function setEvent(?Events $event): static { $this->event = $event; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getNomComplet(): ?string { return $this->nomComplet; }
    public function setNomComplet(string $v): static { $this->nomComplet = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): static { $this->telephone = $v; return $this; }

    public function getNombreAdultes(): int { return $this->nombreAdultes; }
    public function setNombreAdultes(int $v): static { $this->nombreAdultes = $v; return $this; }

    public function getNombreEnfants(): int { return $this->nombreEnfants; }
    public function setNombreEnfants(int $v): static { $this->nombreEnfants = $v; return $this; }

    public function getNombrePersonnes(): int { return $this->nombrePersonnes; }
    public function setNombrePersonnes(int $v): static { $this->nombrePersonnes = $v; return $this; }

    public function getPrixTotal(): ?string { return $this->prixTotal; }
    public function setPrixTotal(string $v): static { $this->prixTotal = $v; return $this; }

    public function getDemandesSpeciales(): ?string { return $this->demandesSpeciales; }
    public function setDemandesSpeciales(?string $v): static { $this->demandesSpeciales = $v; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $v): static { $this->statut = $v; return $this; }

    public function getDateCreation(): ?\DateTimeInterface { return $this->dateCreation; }
    public function setDateCreation(\DateTimeInterface $v): static { $this->dateCreation = $v; return $this; }
}