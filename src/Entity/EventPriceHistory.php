<?php

namespace App\Entity;

use App\Repository\EventPriceHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventPriceHistoryRepository::class)]
#[ORM\Table(name: 'event_price_history')]
#[ORM\HasLifecycleCallbacks]
class EventPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Events::class)]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'événement est obligatoire')]
    private ?Events $event = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'L\'ancien prix est obligatoire')]
    #[Assert\Positive(message: 'L\'ancien prix doit être positif')]
    private ?string $oldPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le nouveau prix est obligatoire')]
    #[Assert\Positive(message: 'Le nouveau prix doit être positif')]
    private ?string $newPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le pourcentage de réduction est obligatoire')]
    #[Assert\Range(
        min: 0,
        max: 1,
        minMessage: 'Le pourcentage doit être entre 0 et 1',
        maxMessage: 'Le pourcentage doit être entre 0 et 1'
    )]
    private ?string $discountPercentage = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $calculationFactors = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank(message: 'La raison du changement est obligatoire')]
    #[Assert\Choice(
        choices: ['low_occupancy', 'time_urgency', 'popularity_boost', 'reversibility', 'manual_adjustment'],
        message: 'La raison doit être une valeur valide'
    )]
    private ?string $reason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isAutomatic = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $appliedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->calculationFactors = json_encode([
            'time' => 0,
            'occupancy' => 0,
            'popularity' => 0,
            'urgency_score' => 0
        ]);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Events
    {
        return $this->event;
    }

    public function setEvent(Events $event): static
    {
        $this->event = $event;
        return $this;
    }

    public function getOldPrice(): ?string
    {
        return $this->oldPrice;
    }

    public function setOldPrice(string $oldPrice): static
    {
        $this->oldPrice = $oldPrice;
        return $this;
    }

    public function getNewPrice(): ?string
    {
        return $this->newPrice;
    }

    public function setNewPrice(string $newPrice): static
    {
        $this->newPrice = $newPrice;
        return $this;
    }

    public function getDiscountPercentage(): ?string
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(string $discountPercentage): static
    {
        $this->discountPercentage = $discountPercentage;
        return $this;
    }

    public function getCalculationFactors(): array
    {
        return $this->calculationFactors ? json_decode($this->calculationFactors, true) : [];
    }

    public function setCalculationFactors(array $calculationFactors): static
    {
        $this->calculationFactors = json_encode($calculationFactors);
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isAutomatic(): bool
    {
        return $this->isAutomatic;
    }

    public function setAutomatic(bool $isAutomatic): static
    {
        $this->isAutomatic = $isAutomatic;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getAppliedAt(): ?\DateTimeInterface
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeInterface $appliedAt): static
    {
        $this->appliedAt = $appliedAt;
        return $this;
    }

    // Méthodes utilitaires

    public function getPriceDifference(): float
    {
        return (float)$this->oldPrice - (float)$this->newPrice;
    }

    public function isPriceIncrease(): bool
    {
        return (float)$this->newPrice > (float)$this->oldPrice;
    }

    public function isPriceDecrease(): bool
    {
        return (float)$this->newPrice < (float)$this->oldPrice;
    }

    public function getDiscountPercentageAsPercent(): float
    {
        return (float)$this->discountPercentage * 100;
    }

    public function getTimeFactor(): float
    {
        return $this->calculationFactors['time'] ?? 0;
    }

    public function getOccupancyFactor(): float
    {
        return $this->calculationFactors['occupancy'] ?? 0;
    }

    public function getPopularityFactor(): float
    {
        return $this->calculationFactors['popularity'] ?? 0;
    }

    public function getUrgencyScore(): float
    {
        return $this->calculationFactors['urgency_score'] ?? 0;
    }

    public function setCalculationFactor(string $key, float $value): static
    {
        $this->calculationFactors[$key] = $value;
        return $this;
    }

    // Labels pour les raisons
    public function getReasonLabel(): string
    {
        return match($this->reason) {
            'low_occupancy' => 'Faible remplissage',
            'time_urgency' => 'Urgence temporelle',
            'popularity_boost' => 'Boost de popularité',
            'reversibility' => 'Réversibilité',
            'manual_adjustment' => 'Ajustement manuel',
            default => 'Inconnue'
        };
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->appliedAt === null) {
            $this->appliedAt = new \DateTime();
        }
    }
}
