<?php

namespace App\Entity;

use App\Repository\GroupChannelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: GroupChannelRepository::class)]
class GroupChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(length: 20)]
    private ?string $canRead = null;

    #[ORM\Column(length: 20)]
    private ?string $canWrite = null;
    
    #[ORM\OneToMany(targetEntity: GroupMessage::class, mappedBy: 'channel', orphanRemoval: true)]
    private Collection $messages;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'channels')]
    #[ORM\JoinColumn(nullable: false)]
    private ?group $usergroup = null;

    public function __construct()
{
    $this->createdAt = new \DateTimeImmutable();
    $this->position = 0;
    $this->canRead = 'member';
    $this->canWrite = 'member';
    $this->messages = new ArrayCollection();
}

public function __toString(): string
{
    return $this->name ?? '';
}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCanRead(): ?string
    {
        return $this->canRead;
    }

    public function setCanRead(string $canRead): static
    {
        $this->canRead = $canRead;

        return $this;
    }

    public function getCanWrite(): ?string
    {
        return $this->canWrite;
    }

    public function setCanWrite(string $canWrite): static
    {
        $this->canWrite = $canWrite;

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

    public function getUsergroup(): ?group
    {
        return $this->usergroup;
    }

    public function setUsergroup(?group $usergroup): static
    {
        $this->usergroup = $usergroup;

        return $this;
    }
}
