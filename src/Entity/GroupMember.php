<?php

namespace App\Entity;

use App\Repository\GroupMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupMemberRepository::class)]
class GroupMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 25)]
    private ?string $role = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\ManyToOne(inversedBy: 'groupMembers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Group $usergroup = null;

    /** Place of the group in the member's own ordering of their groups (custom order of the groups page). */
    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    /** Muted group: no notification for its messages, except when the member is mentioned. */
    #[ORM\Column(options: ['default' => false])]
    private bool $muted = false;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
        $this->role = 'member';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    /** Rôles valides, du moins au plus privilégié. */
    public const ROLE_LEVELS = ['member' => 1, 'admin' => 2, 'owner' => 3];

    /** Vrai si le rôle du membre est au moins égal au rôle requis ('member', 'admin' ou 'owner'). */
    public function hasAtLeastRole(?string $requiredRole): bool
    {
        return (self::ROLE_LEVELS[$this->role] ?? 0) >= (self::ROLE_LEVELS[$requiredRole] ?? 1);
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function setMuted(bool $muted): static
    {
        $this->muted = $muted;

        return $this;
    }

    public function getUsergroup(): ?Group
    {
        return $this->usergroup;
    }

    public function setUsergroup(?Group $usergroup): static
    {
        $this->usergroup = $usergroup;

        return $this;
    }
}
