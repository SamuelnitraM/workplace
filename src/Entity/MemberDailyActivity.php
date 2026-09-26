<?php

namespace App\Entity;

use App\Repository\MemberDailyActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per member and per day on which the member used the site (heartbeat of an open tab).
 * Source of the activity statistics: daily active members, retention.
 */
#[ORM\Entity(repositoryClass: MemberDailyActivityRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_member_daily_activity', columns: ['user_id', 'day'])]
#[ORM\Index(name: 'idx_member_daily_activity_day', columns: ['day'])]
class MemberDailyActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    public function __construct(User $user, \DateTimeImmutable $day)
    {
        $this->user = $user;
        $this->day = $day->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }
}
