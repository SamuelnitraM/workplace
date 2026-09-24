<?php

namespace App\Entity;

use App\Repository\FactionEnhancementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Enhancement of a detachment, synchronised from BSData (army:sync-bsdata).
 * The detachment is referenced by its English name, like ArmyList.detachment.
 */
#[ORM\Entity(repositoryClass: FactionEnhancementRepository::class)]
#[ORM\Index(name: 'idx_faction_enhancement_detachment', columns: ['faction', 'detachment'])]
class FactionEnhancement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $bsdataId;

    #[ORM\Column(length: 155)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $faction;

    #[ORM\Column(length: 155)]
    private string $detachment;

    #[ORM\Column]
    private int $points = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct(string $bsdataId, string $faction, string $detachment)
    {
        $this->bsdataId = $bsdataId;
        $this->faction = $faction;
        $this->detachment = $detachment;
    }

    public function getId(): ?int { return $this->id; }
    public function getBsdataId(): string { return $this->bsdataId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getFaction(): string { return $this->faction; }
    public function getDetachment(): string { return $this->detachment; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $points): static { $this->points = max(0, $points); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
