<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`group`')]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?bool $isPublic = null;

    #[ORM\Column]
    private ?bool $isJoinable = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'CreatedGroups')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $creator = null;

    /**
     * @var Collection<int, GroupMember>
     */
    #[ORM\OneToMany(targetEntity: GroupMember::class, mappedBy: 'usergroup', orphanRemoval: true)]
    private Collection $members;

    /**
     * @var Collection<int, GroupMessage>
     */
    #[ORM\OneToMany(targetEntity: GroupMessage::class, mappedBy: 'usergroup', orphanRemoval: true)]
    private Collection $messages;

    /**
     * @var Collection<int, TodoNode>
     */
    #[ORM\OneToMany(targetEntity: TodoNode::class, mappedBy: 'usergroup')]
    private Collection $todoNodes;

    /**
     * @var Collection<int, GroupInvitation>
     */
    #[ORM\OneToMany(targetEntity: GroupInvitation::class, mappedBy: 'usergroup', orphanRemoval: true)]
    private Collection $invitations;

    /**
     * @var Collection<int, GroupChannel>
     */
    #[ORM\OneToMany(targetEntity: GroupChannel::class, mappedBy: 'usergroup', orphanRemoval: true)]
    private Collection $channels;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->isPublic = true;
        $this->isJoinable = true;
        $this->members = new \Doctrine\Common\Collections\ArrayCollection();
        $this->messages = new \Doctrine\Common\Collections\ArrayCollection();
        $this->todoNodes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invitations = new ArrayCollection();
        $this->channels = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function isPublic(): ?bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    public function isJoinable(): ?bool
    {
        return $this->isJoinable;
    }

    public function setIsJoinable(bool $isJoinable): static
    {
        $this->isJoinable = $isJoinable;

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

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    /**
     * @return Collection<int, GroupMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(GroupMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setUsergroup($this);
        }

        return $this;
    }

    public function removeMember(GroupMember $member): static
    {
        if ($this->members->removeElement($member)) {
            // set the owning side to null (unless already changed)
            if ($member->getUsergroup() === $this) {
                $member->setUsergroup(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(GroupMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setUsergroup($this);
        }

        return $this;
    }

    public function removeMessage(GroupMessage $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getUsergroup() === $this) {
                $message->setUsergroup(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TodoNode>
     */
    public function getTodoNodes(): Collection
    {
        return $this->todoNodes;
    }

    public function addTodoNode(TodoNode $todoNode): static
    {
        if (!$this->todoNodes->contains($todoNode)) {
            $this->todoNodes->add($todoNode);
            $todoNode->setUsergroup($this);
        }

        return $this;
    }

    public function removeTodoNode(TodoNode $todoNode): static
    {
        if ($this->todoNodes->removeElement($todoNode)) {
            // set the owning side to null (unless already changed)
            if ($todoNode->getUsergroup() === $this) {
                $todoNode->setUsergroup(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupInvitation>
     */
    public function getInvitations(): Collection
    {
        return $this->invitations;
    }

    public function addInvitation(GroupInvitation $invitation): static
    {
        if (!$this->invitations->contains($invitation)) {
            $this->invitations->add($invitation);
            $invitation->setUsergroup($this);
        }

        return $this;
    }

    public function removeInvitation(GroupInvitation $invitation): static
    {
        if ($this->invitations->removeElement($invitation)) {
            // set the owning side to null (unless already changed)
            if ($invitation->getUsergroup() === $this) {
                $invitation->setUsergroup(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupChannel>
     */
    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(GroupChannel $channel): static
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
            $channel->setUsergroup($this);
        }

        return $this;
    }

    public function removeChannel(GroupChannel $channel): static
    {
        if ($this->channels->removeElement($channel)) {
            // set the owning side to null (unless already changed)
            if ($channel->getUsergroup() === $this) {
                $channel->setUsergroup(null);
            }
        }

        return $this;
    }
}
