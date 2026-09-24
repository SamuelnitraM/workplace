<?php

namespace App\Entity;

use App\Repository\UserBlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One member blocking another: no private message, friend request or group invitation between them,
 * whichever of the two initiates it.
 */
#[ORM\Entity(repositoryClass: UserBlockRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_BLOCK_PAIR', columns: ['blocker_id', 'blocked_id'])]
class UserBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocker;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $blocker, User $blocked)
    {
        $this->blocker = $blocker;
        $this->blocked = $blocked;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlocker(): User
    {
        return $this->blocker;
    }

    public function getBlocked(): User
    {
        return $this->blocked;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
