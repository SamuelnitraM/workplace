<?php

namespace App\Entity;

use App\Repository\FactionUnitRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FactionUnitRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_bsdata_id_faction', columns: ['bsdata_id', 'faction'])]
class FactionUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private ?string $bsdataId = null;

    #[ORM\Column(length: 155)]
    private ?string $name = null;

    #[ORM\Column(length: 100)]
    private ?string $faction = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column]
    private ?int $points = null;

    #[ORM\Column(length: 100)]
    private ?string $sourceFile = null;

    #[ORM\Column(length: 155, nullable: true)]
    private ?string $nameFr = null;

    #[ORM\Column(nullable: true)]
    private ?array $statsData = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBsdataId(): ?string
    {
        return $this->bsdataId;
    }

    public function setBsdataId(string $bsdataId): static
    {
        $this->bsdataId = $bsdataId;

        return $this;
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

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

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(string $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function getNameFr(): ?string
    {
        return $this->nameFr;
    }

    public function setNameFr(?string $nameFr): static
    {
        $this->nameFr = $nameFr;

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
