<?php

namespace App\Entity;

use App\Repository\GroupInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupInvitationRepository::class)]
class GroupInvitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'sentGroupInvitations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $invitedBy = null;

    #[ORM\ManyToOne(inversedBy: 'receivedGroupInvitations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $invitedUser = null;

    #[ORM\ManyToOne(inversedBy: 'invitations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Group $usergroup = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = 'pending';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getInvitedUser(): ?User
    {
        return $this->invitedUser;
    }

    public function setInvitedUser(?User $invitedUser): static
    {
        $this->invitedUser = $invitedUser;

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
