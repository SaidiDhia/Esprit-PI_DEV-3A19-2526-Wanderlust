<?php

namespace App\Entity;

use App\Repository\FactureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FactureRepository::class)]
#[ORM\Table(name: 'facture')]
class Facture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $idFacture = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateFacture = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalPrice = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $deliveryStatus = null;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private ?string $userId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\OneToMany(mappedBy: 'facture', targetEntity: FactureProduct::class, cascade: ['persist', 'remove'])]
    private Collection $factureProducts;

    #[ORM\OneToOne(mappedBy: 'facture', targetEntity: DeliveryAddress::class, cascade: ['persist', 'remove'])]
    private ?DeliveryAddress $deliveryAddress = null;

    public function __construct()
    {
        $this->factureProducts = new ArrayCollection();
        $this->dateFacture = new \DateTime();
    }

    public function getIdFacture(): ?int
    {
        return $this->idFacture;
    }

    public function getDateFacture(): ?\DateTimeInterface
    {
        return $this->dateFacture;
    }

    public function setDateFacture(\DateTimeInterface $dateFacture): static
    {
        $this->dateFacture = $dateFacture;

        return $this;
    }

    public function getTotalPrice(): ?string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getDeliveryStatus(): ?string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(?string $deliveryStatus): static
    {
        $this->deliveryStatus = $deliveryStatus;

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    /**
     * @return Collection<int, FactureProduct>
     */
    public function getFactureProducts(): Collection
    {
        return $this->factureProducts;
    }

    public function addFactureProduct(FactureProduct $factureProduct): static
    {
        if (!$this->factureProducts->contains($factureProduct)) {
            $this->factureProducts->add($factureProduct);
            $factureProduct->setFacture($this);
        }

        return $this;
    }

    public function removeFactureProduct(FactureProduct $factureProduct): static
    {
        if ($this->factureProducts->removeElement($factureProduct)) {
            if ($factureProduct->getFacture() === $this) {
                $factureProduct->setFacture(null);
            }
        }

        return $this;
    }

    public function getDeliveryAddress(): ?DeliveryAddress
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?DeliveryAddress $deliveryAddress): static
    {
        if ($deliveryAddress === null && $this->deliveryAddress !== null) {
            $this->deliveryAddress->setFacture(null);
        }

        if ($deliveryAddress !== null && $deliveryAddress->getFacture() !== $this) {
            $deliveryAddress->setFacture($this);
        }

        $this->deliveryAddress = $deliveryAddress;

        return $this;
    }
}
