<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_EXPERIENCE_AWARD_KEY', columns: ['user_id', 'action_key'])]
// Classement « XP de la semaine / du mois » : index couvrant (plage de dates → somme par membre)
#[ORM\Index(name: 'IDX_EXPERIENCE_AWARD_CREATED', columns: ['created_at', 'user_id', 'amount'])]
class ExperienceAward
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 150)]
    private string $actionKey;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $actionKey, int $amount)
    {
        $this->user = $user;
        $this->actionKey = $actionKey;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }
}