<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_BADGE_CODE', columns: ['code'])]
class Badge
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	#[ORM\Column(length: 80)]
	private string $code = '';

	#[ORM\Column(length: 120)]
	private string $name = '';

	#[ORM\Column(length: 30)]
	private string $category = 'Contribution';

	#[ORM\Column(type: Types::TEXT)]
	private string $description = '';

	#[ORM\Column(type: Types::TEXT)]
	private string $hiddenDescription = 'Condition secrète à découvrir';

	#[ORM\Column(length: 20)]
	private string $icon = '🏅';

	#[ORM\Column]
	private bool $hidden = false;

	#[ORM\Column]
	private int $xpReward = 50;

	public function getId(): ?int { return $this->id; }
	public function getCode(): string { return $this->code; }
	public function setCode(string $code): static { $this->code = $code; return $this; }
	public function getName(): string { return $this->name; }
	public function setName(string $name): static { $this->name = $name; return $this; }
	public function getCategory(): string { return $this->category; }
	public function setCategory(string $category): static { $this->category = $category; return $this; }
	public function getDescription(): string { return $this->description; }
	public function setDescription(string $description): static { $this->description = $description; return $this; }
	public function getHiddenDescription(): string { return $this->hiddenDescription; }
	public function setHiddenDescription(string $hiddenDescription): static { $this->hiddenDescription = $hiddenDescription; return $this; }
	public function getIcon(): string { return $this->icon; }
	public function setIcon(string $icon): static { $this->icon = $icon; return $this; }
	public function isHidden(): bool { return $this->hidden; }
	public function setHidden(bool $hidden): static { $this->hidden = $hidden; return $this; }
	public function getXpReward(): int { return $this->xpReward; }
	public function setXpReward(int $xpReward): static { $this->xpReward = max(0, $xpReward); return $this; }
}
