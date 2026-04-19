<?php

namespace App\Entity;

use App\Repository\DynamicPricingRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DynamicPricingRuleRepository::class)]
#[ORM\Table(name: 'dynamic_pricing_rules')]
class DynamicPricingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    #[Assert\NotBlank(message: 'Le type d\'événement est obligatoire')]
    #[Assert\Length(max: 50, maxMessage: 'Le type ne peut pas dépasser {{ limit }} caractères')]
    private ?string $eventType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix plancher est obligatoire')]
    #[Assert\Positive(message: 'Le prix plancher doit être positif')]
    #[Assert\Range(
        min: 0.1,
        max: 0.9,
        minMessage: 'Le prix plancher ne peut être inférieur à 10% du prix de base',
        maxMessage: 'Le prix plancher ne peut dépasser 90% du prix de base'
    )]
    private ?string $emotionalFloorPercentage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'La réduction maximale est obligatoire')]
    #[Assert\Positive(message: 'La réduction maximale doit être positive')]
    #[Assert\Range(
        min: 0.05,
        max: 0.6,
        minMessage: 'La réduction minimale est de 5%',
        maxMessage: 'La réduction maximale est de 60%'
    )]
    private ?string $maxDiscountPercentage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    #[Assert\NotBlank(message: 'Le poids temporel est obligatoire')]
    #[Assert\Range(
        min: 0,
        max: 1,
        minMessage: 'Le poids doit être entre 0 et 1',
        maxMessage: 'Le poids doit être entre 0 et 1'
    )]
    private ?string $timeWeight = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    #[Assert\NotBlank(message: 'Le poids de remplissage est obligatoire')]
    #[Assert\Range(
        min: 0,
        max: 1,
        minMessage: 'Le poids doit être entre 0 et 1',
        maxMessage: 'Le poids doit être entre 0 et 1'
    )]
    private ?string $occupancyWeight = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    #[Assert\NotBlank(message: 'Le poids de popularité est obligatoire')]
    #[Assert\Range(
        min: 0,
        max: 1,
        minMessage: 'Le poids doit être entre 0 et 1',
        maxMessage: 'Le poids doit être entre 0 et 1'
    )]
    private ?string $popularityWeight = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le seuil de remplissage est obligatoire')]
    #[Assert\Range(
        min: 10,
        max: 90,
        minMessage: 'Le seuil doit être au moins 10%',
        maxMessage: 'Le seuil ne peut dépasser 90%'
    )]
    private ?int $occupancyThreshold = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le facteur de réversibilité est obligatoire')]
    #[Assert\Range(
        min: 1,
        max: 10,
        minMessage: 'Le facteur doit être au moins 1',
        maxMessage: 'Le facteur ne peut dépasser 10'
    )]
    private ?int $reversibilityFactor = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->emotionalFloorPercentage = '0.50'; // 50% par défaut
        $this->maxDiscountPercentage = '0.40'; // 40% par défaut
        $this->timeWeight = '0.40';
        $this->occupancyWeight = '0.35';
        $this->popularityWeight = '0.25';
        $this->occupancyThreshold = 70; // 70% par défaut
        $this->reversibilityFactor = 3; // 3 jours par défaut
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getEmotionalFloorPercentage(): ?string
    {
        return $this->emotionalFloorPercentage;
    }

    public function setEmotionalFloorPercentage(string $emotionalFloorPercentage): static
    {
        $this->emotionalFloorPercentage = $emotionalFloorPercentage;
        return $this;
    }

    public function getMaxDiscountPercentage(): ?string
    {
        return $this->maxDiscountPercentage;
    }

    public function setMaxDiscountPercentage(string $maxDiscountPercentage): static
    {
        $this->maxDiscountPercentage = $maxDiscountPercentage;
        return $this;
    }

    public function getTimeWeight(): ?string
    {
        return $this->timeWeight;
    }

    public function setTimeWeight(string $timeWeight): static
    {
        $this->timeWeight = $timeWeight;
        return $this;
    }

    public function getOccupancyWeight(): ?string
    {
        return $this->occupancyWeight;
    }

    public function setOccupancyWeight(string $occupancyWeight): static
    {
        $this->occupancyWeight = $occupancyWeight;
        return $this;
    }

    public function getPopularityWeight(): ?string
    {
        return $this->popularityWeight;
    }

    public function setPopularityWeight(string $popularityWeight): static
    {
        $this->popularityWeight = $popularityWeight;
        return $this;
    }

    public function getOccupancyThreshold(): ?int
    {
        return $this->occupancyThreshold;
    }

    public function setOccupancyThreshold(int $occupancyThreshold): static
    {
        $this->occupancyThreshold = $occupancyThreshold;
        return $this;
    }

    public function getReversibilityFactor(): ?int
    {
        return $this->reversibilityFactor;
    }

    public function setReversibilityFactor(int $reversibilityFactor): static
    {
        $this->reversibilityFactor = $reversibilityFactor;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // Méthodes utilitaires pour la logique métier

    public function getTotalWeight(): float
    {
        return (float)$this->timeWeight + (float)$this->occupancyWeight + (float)$this->popularityWeight;
    }

    public function isValidWeightDistribution(): bool
    {
        $total = $this->getTotalWeight();
        return abs($total - 1.0) < 0.01; // Tolérance de 1%
    }

    public function getEmotionalFloorPrice(float $basePrice): float
    {
        return $basePrice * (float)$this->emotionalFloorPercentage;
    }

    public function getMaxDiscountPrice(float $basePrice): float
    {
        return $basePrice * (1 - (float)$this->maxDiscountPercentage);
    }
}
