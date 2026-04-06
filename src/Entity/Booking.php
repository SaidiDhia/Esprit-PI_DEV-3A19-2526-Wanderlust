<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'booking')]
class Booking
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_COMPLETED = 'COMPLETED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Place::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(name: 'place_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Place $place = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $guest = null;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(name: 'total_price', type: 'decimal', precision: 10, scale: 2)]
    private string $totalPrice = '0.00';

    #[ORM\Column(name: 'guests_count', type: 'integer')]
    private int $guestsCount = 1;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'refund_amount', type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $refundAmount = '0.00';

    #[ORM\Column(name: 'cancelled_by', type: 'string', length: 10, nullable: true)]
    private ?string $cancelledBy = null;

    #[ORM\Column(name: 'cancel_reason', type: 'string', length: 255, nullable: true)]
    private ?string $cancelReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): ?Place
    {
        return $this->place;
    }

    public function setPlace(Place $place): self
    {
        $this->place = $place;

        return $this;
    }

    public function getGuest(): ?User
    {
        return $this->guest;
    }

    public function setGuest(User $guest): self
    {
        $this->guest = $guest;

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string|float $totalPrice): self
    {
        $this->totalPrice = (string) $totalPrice;

        return $this;
    }

    public function getGuestsCount(): int
    {
        return $this->guestsCount;
    }

    public function setGuestsCount(int $guestsCount): self
    {
        $this->guestsCount = $guestsCount;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getRefundAmount(): string
    {
        return $this->refundAmount;
    }

    public function setRefundAmount(string|float $refundAmount): self
    {
        $this->refundAmount = (string) $refundAmount;

        return $this;
    }

    public function getCancelledBy(): ?string
    {
        return $this->cancelledBy;
    }

    public function setCancelledBy(?string $cancelledBy): self
    {
        $this->cancelledBy = $cancelledBy;

        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): self
    {
        $this->cancelReason = $cancelReason;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}