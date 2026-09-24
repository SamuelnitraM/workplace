<?php

namespace App\Entity;

use App\Army\BattleSize;
use App\Repository\ArmyListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArmyListRepository::class)]
class ArmyList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 155)]
    private ?string $faction = null;

    #[ORM\Column(length: 155, nullable: true)]
    private ?string $detachment = null;

    #[ORM\Column]
    private ?int $totalPoints = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?bool $isPublic = null;

    #[ORM\ManyToOne(inversedBy: 'armyLists')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    /**
     * @var Collection<int, ArmyUnit>
     */
    #[ORM\OneToMany(targetEntity: ArmyUnit::class, mappedBy: 'armylist', orphanRemoval: true, cascade: ['persist'])]
    private Collection $units;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Official list (matched play rules checked, points limit) when set; free list otherwise. */
    #[ORM\Column(nullable: true, enumType: BattleSize::class)]
    private ?BattleSize $battleSize = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->totalPoints = 0;
        $this->isPublic = false;
        $this->units = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBattleSize(): ?BattleSize
    {
        return $this->battleSize;
    }

    public function setBattleSize(?BattleSize $battleSize): static
    {
        $this->battleSize = $battleSize;
        return $this;
    }

    public function isOfficial(): bool
    {
        return $this->battleSize !== null;
    }

    /** Private copy of the list (units included) owned by another member. */
    public function duplicateFor(User $owner, string $name): self
    {
        $copy = (new self())
            ->setName($name)
            ->setFaction((string) $this->faction)
            ->setDetachment($this->detachment)
            ->setDescription($this->description)
            ->setBattleSize($this->battleSize)
            ->setOwner($owner);
        foreach ($this->units as $unit) {
            $copy->addUnit($unit->duplicate());
        }
        return $copy->setTotalPoints($copy->computeTotalPoints());
    }

    /** Sum of every unit, enhancements included. */
    public function computeTotalPoints(): int
    {
        $total = 0;
        foreach ($this->units as $unit) {
            $total += $unit->getTotalPoints();
        }
        return $total;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFaction(): ?string
    {
        return $this->faction;
    }

    public function setFaction(string $faction): static
    {
        $this->faction = $faction;

        return $this;
    }

    public function getDetachment(): ?string
    {
        return $this->detachment;
    }

    public function setDetachment(?string $detachment): static
    {
        $this->detachment = $detachment;

        return $this;
    }

    public function getTotalPoints(): ?int
    {
        return $this->totalPoints;
    }

    public function setTotalPoints(int $totalPoints): static
    {
        $this->totalPoints = $totalPoints;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isPublic(): ?bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, ArmyUnit>
     */
    public function getUnits(): Collection
    {
        return $this->units;
    }

    public function addUnit(ArmyUnit $unit): static
    {
        if (!$this->units->contains($unit)) {
            $this->units->add($unit);
            $unit->setArmylist($this);
        }

        return $this;
    }

    public function removeUnit(ArmyUnit $unit): static
    {
        if ($this->units->removeElement($unit)) {
            // set the owning side to null (unless already changed)
            if ($unit->getArmylist() === $this) {
                $unit->setArmylist(null);
            }
        }

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
}
