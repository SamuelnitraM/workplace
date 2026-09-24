<?php

namespace App\Entity;

use App\Repository\ArmyUnitRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArmyUnitRepository::class)]
class ArmyUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 155)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?int $points = null;

    #[ORM\Column(nullable: true)]
    private ?array $options = null;

    #[ORM\ManyToOne(inversedBy: 'units')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ArmyList $armylist = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(nullable: true)]
    private ?array $statsData = null;

    /** Number of models chosen among the unit sizes (statsData.pointsOptions); NULL: default size. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $modelCount = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $warlord = false;

    #[ORM\Column(length: 155, nullable: true)]
    private ?string $enhancementName = null;

    /** Points of the enhancement, copied at selection time like the unit points. */
    #[ORM\Column(options: ['default' => 0])]
    private int $enhancementPoints = 0;

    public function __construct()
    {
        $this->quantity = 1;
        $this->points = 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModelCount(): ?int
    {
        return $this->modelCount;
    }

    public function setModelCount(?int $modelCount): static
    {
        $this->modelCount = $modelCount;
        return $this;
    }

    public function isWarlord(): bool
    {
        return $this->warlord;
    }

    public function setWarlord(bool $warlord): static
    {
        $this->warlord = $warlord;
        return $this;
    }

    public function getEnhancementName(): ?string
    {
        return $this->enhancementName;
    }

    public function getEnhancementPoints(): int
    {
        return $this->enhancementPoints;
    }

    public function setEnhancement(?string $name, int $points): static
    {
        $this->enhancementName = $name;
        $this->enhancementPoints = $name !== null ? max(0, $points) : 0;
        return $this;
    }

    /** Copy of the unit, detached from its list. */
    public function duplicate(): self
    {
        return (new self())
            ->setName((string) $this->name)
            ->setQuantity((int) $this->quantity)
            ->setPoints((int) $this->points)
            ->setCategory($this->category)
            ->setStatsData($this->statsData)
            ->setOptions($this->options)
            ->setModelCount($this->modelCount)
            ->setWarlord($this->warlord)
            ->setEnhancement($this->enhancementName, $this->enhancementPoints);
    }

    /** Unit cost times quantity, plus its enhancement. */
    public function getTotalPoints(): int
    {
        return (int) $this->points * (int) $this->quantity + $this->enhancementPoints;
    }

    /** @return list<string> keywords of the datasheet (copied BSData profile) */
    public function getKeywords(): array
    {
        $keywords = $this->statsData['keywords'] ?? [];
        return is_array($keywords) ? array_values(array_filter($keywords, 'is_string')) : [];
    }

    public function hasKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->getKeywords(), true);
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

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getArmylist(): ?ArmyList
    {
        return $this->armylist;
    }

    public function setArmylist(?ArmyList $armylist): static
    {
        $this->armylist = $armylist;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getStatsData(): ?array
    {
        return $this->statsData;
    }

    public function setStatsData(?array $statsData): static
    {
        $this->statsData = $statsData;

        return $this;
    }
}
