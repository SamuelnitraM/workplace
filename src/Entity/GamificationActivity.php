<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_GAMIFICATION_ACTIVITY', columns: ['user_id', 'activity_key'])]
class GamificationActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $activityKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $activityKey)
    {
        $this->user = $user;
        $this->activityKey = $activityKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getActivityKey(): string { return $this->activityKey; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
