<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_BADGE', columns: ['user_id', 'badge_id'])]
class UserBadge
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	#[ORM\ManyToOne]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private User $user;

	#[ORM\ManyToOne]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Badge $badge;

	#[ORM\Column]
	private \DateTimeImmutable $unlockedAt;

	public function __construct(?User $user = null, ?Badge $badge = null)
	{
		if ($user) {
			$this->user = $user;
		}
		if ($badge) {
			$this->badge = $badge;
		}
		$this->unlockedAt = new \DateTimeImmutable();
	}

	public function getId(): ?int { return $this->id; }
	public function getUser(): User { return $this->user; }
	public function setUser(User $user): static { $this->user = $user; return $this; }
	public function getBadge(): Badge { return $this->badge; }
	public function setBadge(Badge $badge): static { $this->badge = $badge; return $this; }
	public function getUnlockedAt(): \DateTimeImmutable { return $this->unlockedAt; }
}
