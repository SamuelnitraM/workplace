<?php

namespace App\Entity;

use App\Repository\GroupMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupMessageRepository::class)]
#[ORM\Index(name: 'idx_group_message_channel_pinned', columns: ['channel_id', 'pinned_at'])]
class GroupMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'groupMessages')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Group $usergroup = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    private ?GroupChannel $channel = null;

    /** Date d'épinglage dans le channel (null : message non épinglé). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pinnedAt = null;

    /** Auteur de l'épinglage (null si non épinglé ou si son compte a été supprimé). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $pinnedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

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

    public function getChannel(): ?GroupChannel
    {
        return $this->channel;
    }

    public function setChannel(?GroupChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getPinnedAt(): ?\DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    public function isPinned(): bool
    {
        return $this->pinnedAt !== null;
    }

    public function pin(User $by): static
    {
        $this->pinnedAt = new \DateTimeImmutable();
        $this->pinnedBy = $by;

        return $this;
    }

    public function unpin(): static
    {
        $this->pinnedAt = null;
        $this->pinnedBy = null;

        return $this;
    }
}
