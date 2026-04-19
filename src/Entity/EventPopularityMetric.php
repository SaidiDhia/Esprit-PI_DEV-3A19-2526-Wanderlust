<?php

namespace App\Entity;

use App\Repository\EventPopularityMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventPopularityMetricRepository::class)]
#[ORM\Table(name: 'event_popularity_metrics')]
#[ORM\HasLifecycleCallbacks]
class EventPopularityMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Events::class)]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'événement est obligatoire')]
    private ?Events $event = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire')]
    private \DateTimeInterface $date;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le nombre de vues est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de vues doit être positif')]
    private ?int $viewsCount = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le nombre d\'abandons de panier est obligatoire')]
    #[Assert\Positive(message: 'Le nombre d\'abandons doit être positif')]
    private ?int $cartAbandonments = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le nombre de partages sociaux est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de partages doit être positif')]
    private ?int $socialShares = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le nombre de mentions est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de mentions doit être positif')]
    private ?int $searchMentions = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le nombre de réservations est obligatoire')]
    #[Assert\Positive(message: 'Le nombre de réservations doit être positif')]
    private ?int $reservationsCount = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotBlank(message: 'Le temps moyen sur page est obligatoire')]
    #[Assert\Positive(message: 'Le temps moyen doit être positif')]
    private ?int $averageTimeOnPage = null; // en secondes

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le score calculé est obligatoire')]
    #[Assert\Range(
        min: 0,
        max: 1,
        minMessage: 'Le score doit être entre 0 et 1',
        maxMessage: 'Le score doit être entre 0 et 1'
    )]
    private ?string $calculatedScore = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $rawMetrics = '';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->date = new \DateTime();
        $this->viewsCount = 0;
        $this->cartAbandonments = 0;
        $this->socialShares = 0;
        $this->searchMentions = 0;
        $this->reservationsCount = 0;
        $this->averageTimeOnPage = 0;
        $this->calculatedScore = '0.00';
        $this->rawMetrics = json_encode([]);
        $this->createdAt = new \DateTime();
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

    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getViewsCount(): ?int
    {
        return $this->viewsCount;
    }

    public function setViewsCount(int $viewsCount): static
    {
        $this->viewsCount = $viewsCount;
        return $this;
    }

    public function getCartAbandonments(): ?int
    {
        return $this->cartAbandonments;
    }

    public function setCartAbandonments(int $cartAbandonments): static
    {
        $this->cartAbandonments = $cartAbandonments;
        return $this;
    }

    public function getSocialShares(): ?int
    {
        return $this->socialShares;
    }

    public function setSocialShares(int $socialShares): static
    {
        $this->socialShares = $socialShares;
        return $this;
    }

    public function getSearchMentions(): ?int
    {
        return $this->searchMentions;
    }

    public function setSearchMentions(int $searchMentions): static
    {
        $this->searchMentions = $searchMentions;
        return $this;
    }

    public function getReservationsCount(): ?int
    {
        return $this->reservationsCount;
    }

    public function setReservationsCount(int $reservationsCount): static
    {
        $this->reservationsCount = $reservationsCount;
        return $this;
    }

    public function getAverageTimeOnPage(): ?int
    {
        return $this->averageTimeOnPage;
    }

    public function setAverageTimeOnPage(int $averageTimeOnPage): static
    {
        $this->averageTimeOnPage = $averageTimeOnPage;
        return $this;
    }

    public function getCalculatedScore(): ?string
    {
        return $this->calculatedScore;
    }

    public function setCalculatedScore(string $calculatedScore): static
    {
        $this->calculatedScore = $calculatedScore;
        return $this;
    }

    public function getRawMetrics(): array
    {
        return $this->rawMetrics ? json_decode($this->rawMetrics, true) : [];
    }

    public function setRawMetrics(array $rawMetrics): static
    {
        $this->rawMetrics = json_encode($rawMetrics);
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

    // Méthodes utilitaires pour le calcul de popularité

    public function calculatePopularityScore(): float
    {
        // Pondération des différentes métriques
        $weights = [
            'views' => 0.25,
            'reservations' => 0.30,
            'social_shares' => 0.20,
            'time_on_page' => 0.15,
            'search_mentions' => 0.10
        ];

        // Normalisation des métriques (valeurs maximales attendues)
        $maxValues = [
            'views' => 1000,
            'reservations' => 100,
            'social_shares' => 50,
            'time_on_page' => 300, // 5 minutes
            'search_mentions' => 20
        ];

        $score = 0;
        $score += min($this->viewsCount / $maxValues['views'], 1) * $weights['views'];
        $score += min($this->reservationsCount / $maxValues['reservations'], 1) * $weights['reservations'];
        $score += min($this->socialShares / $maxValues['social_shares'], 1) * $weights['social_shares'];
        $score += min($this->averageTimeOnPage / $maxValues['time_on_page'], 1) * $weights['time_on_page'];
        $score += min($this->searchMentions / $maxValues['search_mentions'], 1) * $weights['search_mentions'];

        return min($score, 1.0); // Maximum 1.0
    }

    public function updateCalculatedScore(): static
    {
        $this->calculatedScore = number_format($this->calculatePopularityScore(), 2);
        return $this;
    }

    public function getEngagementRate(): float
    {
        $totalInteractions = $this->socialShares + $this->searchMentions + $this->reservationsCount;
        return $this->viewsCount > 0 ? $totalInteractions / $this->viewsCount : 0;
    }

    public function getConversionRate(): float
    {
        return $this->viewsCount > 0 ? $this->reservationsCount / $this->viewsCount : 0;
    }

    public function getCartAbandonmentRate(): float
    {
        $totalCartActions = $this->reservationsCount + $this->cartAbandonments;
        return $totalCartActions > 0 ? $this->cartAbandonments / $totalCartActions : 0;
    }

    // Méthodes pour ajouter des métriques brutes
    public function addRawMetric(string $key, mixed $value): static
    {
        $this->rawMetrics[$key] = $value;
        return $this;
    }

    public function getRawMetric(string $key, mixed $default = null): mixed
    {
        return $this->rawMetrics[$key] ?? $default;
    }

    // Labels pour l'interprétation
    public function getPopularityLevel(): string
    {
        $score = (float)$this->calculatedScore;
        if ($score >= 0.8) return 'Très élevée';
        if ($score >= 0.6) return 'Élevée';
        if ($score >= 0.4) return 'Moyenne';
        if ($score >= 0.2) return 'Faible';
        return 'Très faible';
    }

    public function getPopularityColor(): string
    {
        $score = (float)$this->calculatedScore;
        if ($score >= 0.8) return '#28a745'; // Vert
        if ($score >= 0.6) return '#20c997'; // Turquoise
        if ($score >= 0.4) return '#ffc107'; // Jaune
        if ($score >= 0.2) return '#fd7e14'; // Orange
        return '#dc3545'; // Rouge
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onPrePersistAndUpdate(): void
    {
        $this->updatedAt = new \DateTime();
        $this->updateCalculatedScore();
    }
}
