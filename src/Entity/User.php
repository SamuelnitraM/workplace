<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 55)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?bool $isVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 155, nullable: true)]
    private ?string $favoriteFaction = null;

    #[ORM\Column]
    private bool $showActivity = true;

    #[ORM\Column]
    private int $experience = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastDailyLoginAt = null;

    #[ORM\Column]
    private int $loginStreak = 0;

    #[ORM\Column]
    private bool $profileBonusAwarded = false;

    /**
     * @var Collection<int, Thread>
     */
    #[ORM\OneToMany(targetEntity: Thread::class, mappedBy: 'author')]
    private Collection $threads;

    /**
     * @var Collection<int, Post>
     */
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'author')]
    private Collection $posts;

    /**
     * @var Collection<int, Friendship>
     */
    #[ORM\OneToMany(targetEntity: Friendship::class, mappedBy: 'requester')]
    private Collection $friendshipAsRequester;

    /**
     * @var Collection<int, Friendship>
     */
    #[ORM\OneToMany(targetEntity: Friendship::class, mappedBy: 'receiver')]
    private Collection $friendshipAsReceiver;

    /**
     * @var Collection<int, TodoNode>
     */
    #[ORM\OneToMany(targetEntity: TodoNode::class, mappedBy: 'owner')]
    private Collection $todoNodes;

    /**
     * @var Collection<int, Group>
     */
    #[ORM\OneToMany(targetEntity: Group::class, mappedBy: 'creator')]
    private Collection $CreatedGroups;

    /**
     * @var Collection<int, GroupMember>
     */
    #[ORM\OneToMany(targetEntity: GroupMember::class, mappedBy: 'user')]
    private Collection $groupMembers;

    /**
     * @var Collection<int, GroupMessage>
     */
    #[ORM\OneToMany(targetEntity: GroupMessage::class, mappedBy: 'author')]
    private Collection $groupMessages;

    /**
     * @var Collection<int, TodoNode>
     */
    #[ORM\OneToMany(targetEntity: TodoNode::class, mappedBy: 'assignedTo')]
    private Collection $assignedTodos;

    /**
     * @var Collection<int, GroupInvitation>
     */
    #[ORM\OneToMany(targetEntity: GroupInvitation::class, mappedBy: 'invitedBy')]
    private Collection $sentGroupInvitations;

    /**
     * @var Collection<int, GroupInvitation>
     */
    #[ORM\OneToMany(targetEntity: GroupInvitation::class, mappedBy: 'invitedUser')]
    private Collection $receivedGroupInvitations;

    /**
     * @var Collection<int, PrivateConversation>
     */
    #[ORM\OneToMany(targetEntity: PrivateConversation::class, mappedBy: 'participant1')]
    private Collection $conversationAsParticipant1;

    /**
     * @var Collection<int, PrivateConversation>
     */
    #[ORM\OneToMany(targetEntity: PrivateConversation::class, mappedBy: 'participant2')]
    private Collection $conversationAsParticipant2;

    /**
     * @var Collection<int, PrivateMessage>
     */
    #[ORM\OneToMany(targetEntity: PrivateMessage::class, mappedBy: 'author')]
    private Collection $privateMessages;

    /**
     * @var Collection<int, ArmyList>
     */
    #[ORM\OneToMany(targetEntity: ArmyList::class, mappedBy: 'owner')]
    private Collection $armyLists;

    #[ORM\OneToMany(targetEntity: GalleryPhoto::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $galleryPhotos;

    public function __toString(): string
    {
        return $this->username ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->isVerified = false;
        $this->threads = new ArrayCollection();
        $this->posts = new ArrayCollection();
        $this->friendshipAsRequester = new ArrayCollection();
        $this->friendshipAsReceiver = new ArrayCollection();
        $this->todoNodes = new ArrayCollection();
        $this->CreatedGroups = new ArrayCollection();
        $this->groupMembers = new ArrayCollection();
        $this->groupMessages = new ArrayCollection();
        $this->assignedTodos = new ArrayCollection();
        $this->sentGroupInvitations = new ArrayCollection();
        $this->receivedGroupInvitations = new ArrayCollection();
        $this->conversationAsParticipant1 = new ArrayCollection();
        $this->conversationAsParticipant2 = new ArrayCollection();
        $this->privateMessages = new ArrayCollection();
        $this->armyLists = new ArrayCollection();
        $this->galleryPhotos = new ArrayCollection();
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

    public function isVerified(): ?bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getFavoriteFaction(): ?string
    {
        return $this->favoriteFaction;
    }

    public function setFavoriteFaction(?string $favoriteFaction): static
    {
        $this->favoriteFaction = $favoriteFaction;

        return $this;
    }

    public function getShowActivity(): bool
    {
        return $this->showActivity;
    }

    public function isShowActivity(): bool
    {
        return $this->showActivity;
    }

    public function setShowActivity(bool $showActivity): static
    {
        $this->showActivity = $showActivity;

        return $this;
    }

    public function getExperience(): int { return $this->experience; }
    public function addExperience(int $amount): static { $this->experience += max(0, $amount); return $this; }
    public function getLevel(): int
    {
        $level = 1;
        for ($candidate = 2; $candidate <= 50; $candidate++) {
            if ($this->getExperienceForLevel($candidate) > $this->experience) break;
            $level = $candidate;
        }
        return $level;
    }
    public function getExperienceForLevel(int $level): int { return $level <= 1 ? 0 : (int) round(100 * (($level - 1) ** 1.5)); }
    public function getExperienceProgress(): int
    {
        if ($this->getLevel() >= 50) return 100;
        $current = $this->getExperienceForLevel($this->getLevel());
        $next = $this->getExperienceForLevel($this->getLevel() + 1);
        return (int) min(100, floor(($this->experience - $current) / max(1, $next - $current) * 100));
    }
    public function getLastDailyLoginAt(): ?\DateTimeImmutable { return $this->lastDailyLoginAt; }
    public function setLastDailyLoginAt(?\DateTimeImmutable $value): static { $this->lastDailyLoginAt = $value; return $this; }
    public function getLoginStreak(): int { return $this->loginStreak; }
    public function setLoginStreak(int $value): static { $this->loginStreak = max(0, $value); return $this; }
    public function isProfileBonusAwarded(): bool { return $this->profileBonusAwarded; }
    public function setProfileBonusAwarded(bool $value): static { $this->profileBonusAwarded = $value; return $this; }

    public function getGalleryPhotos(): Collection
    {
        return $this->galleryPhotos;
    }

    /**
     * @return Collection<int, Thread>
     */
    public function getThreads(): Collection
    {
        return $this->threads;
    }

    public function addThread(Thread $thread): static
    {
        if (!$this->threads->contains($thread)) {
            $this->threads->add($thread);
            $thread->setAuthor($this);
        }

        return $this;
    }

    public function removeThread(Thread $thread): static
    {
        if ($this->threads->removeElement($thread)) {
            // set the owning side to null (unless already changed)
            if ($thread->getAuthor() === $this) {
                $thread->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Post>
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    public function addPost(Post $post): static
    {
        if (!$this->posts->contains($post)) {
            $this->posts->add($post);
            $post->setAuthor($this);
        }

        return $this;
    }

    public function removePost(Post $post): static
    {
        if ($this->posts->removeElement($post)) {
            // set the owning side to null (unless already changed)
            if ($post->getAuthor() === $this) {
                $post->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Friendship>
     */
    public function getFriendshipAsRequester(): Collection
    {
        return $this->friendshipAsRequester;
    }

    public function addFriendshipAsRequester(Friendship $friendshipAsRequester): static
    {
        if (!$this->friendshipAsRequester->contains($friendshipAsRequester)) {
            $this->friendshipAsRequester->add($friendshipAsRequester);
            $friendshipAsRequester->setRequester($this);
        }

        return $this;
    }

    public function removeFriendshipAsRequester(Friendship $friendshipAsRequester): static
    {
        if ($this->friendshipAsRequester->removeElement($friendshipAsRequester)) {
            // set the owning side to null (unless already changed)
            if ($friendshipAsRequester->getRequester() === $this) {
                $friendshipAsRequester->setRequester(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Friendship>
     */
    public function getFriendshipAsReceiver(): Collection
    {
        return $this->friendshipAsReceiver;
    }

    public function addFriendshipAsReceiver(Friendship $friendshipAsReceiver): static
    {
        if (!$this->friendshipAsReceiver->contains($friendshipAsReceiver)) {
            $this->friendshipAsReceiver->add($friendshipAsReceiver);
            $friendshipAsReceiver->setReceiver($this);
        }

        return $this;
    }

    public function removeFriendshipAsReceiver(Friendship $friendshipAsReceiver): static
    {
        if ($this->friendshipAsReceiver->removeElement($friendshipAsReceiver)) {
            // set the owning side to null (unless already changed)
            if ($friendshipAsReceiver->getReceiver() === $this) {
                $friendshipAsReceiver->setReceiver(null);
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
            $todoNode->setOwner($this);
        }

        return $this;
    }

    public function removeTodoNode(TodoNode $todoNode): static
    {
        if ($this->todoNodes->removeElement($todoNode)) {
            // set the owning side to null (unless already changed)
            if ($todoNode->getOwner() === $this) {
                $todoNode->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Group>
     */
    public function getCreatedGroups(): Collection
    {
        return $this->CreatedGroups;
    }

    public function addCreatedGroup(Group $createdGroup): static
    {
        if (!$this->CreatedGroups->contains($createdGroup)) {
            $this->CreatedGroups->add($createdGroup);
            $createdGroup->setCreator($this);
        }

        return $this;
    }

    public function removeCreatedGroup(Group $createdGroup): static
    {
        if ($this->CreatedGroups->removeElement($createdGroup)) {
            // set the owning side to null (unless already changed)
            if ($createdGroup->getCreator() === $this) {
                $createdGroup->setCreator(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupMember>
     */
    public function getGroupMembers(): Collection
    {
        return $this->groupMembers;
    }

    public function addGroupMember(GroupMember $groupMember): static
    {
        if (!$this->groupMembers->contains($groupMember)) {
            $this->groupMembers->add($groupMember);
            $groupMember->setUser($this);
        }

        return $this;
    }

    public function removeGroupMember(GroupMember $groupMember): static
    {
        if ($this->groupMembers->removeElement($groupMember)) {
            // set the owning side to null (unless already changed)
            if ($groupMember->getUser() === $this) {
                $groupMember->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupMessage>
     */
    public function getGroupMessages(): Collection
    {
        return $this->groupMessages;
    }

    public function addGroupMessage(GroupMessage $groupMessage): static
    {
        if (!$this->groupMessages->contains($groupMessage)) {
            $this->groupMessages->add($groupMessage);
            $groupMessage->setAuthor($this);
        }

        return $this;
    }

    public function removeGroupMessage(GroupMessage $groupMessage): static
    {
        if ($this->groupMessages->removeElement($groupMessage)) {
            // set the owning side to null (unless already changed)
            if ($groupMessage->getAuthor() === $this) {
                $groupMessage->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TodoNode>
     */
    public function getAssignedTodos(): Collection
    {
        return $this->assignedTodos;
    }

    public function addAssignedTodo(TodoNode $assignedTodo): static
    {
        if (!$this->assignedTodos->contains($assignedTodo)) {
            $this->assignedTodos->add($assignedTodo);
            $assignedTodo->setAssignedTo($this);
        }

        return $this;
    }

    public function removeAssignedTodo(TodoNode $assignedTodo): static
    {
        if ($this->assignedTodos->removeElement($assignedTodo)) {
            // set the owning side to null (unless already changed)
            if ($assignedTodo->getAssignedTo() === $this) {
                $assignedTodo->setAssignedTo(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupInvitation>
     */
    public function getSentGroupInvitations(): Collection
    {
        return $this->sentGroupInvitations;
    }

    public function addSentGroupInvitation(GroupInvitation $sentGroupInvitation): static
    {
        if (!$this->sentGroupInvitations->contains($sentGroupInvitation)) {
            $this->sentGroupInvitations->add($sentGroupInvitation);
            $sentGroupInvitation->setInvitedBy($this);
        }

        return $this;
    }

    public function removeSentGroupInvitation(GroupInvitation $sentGroupInvitation): static
    {
        if ($this->sentGroupInvitations->removeElement($sentGroupInvitation)) {
            // set the owning side to null (unless already changed)
            if ($sentGroupInvitation->getInvitedBy() === $this) {
                $sentGroupInvitation->setInvitedBy(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GroupInvitation>
     */
    public function getReceivedGroupInvitations(): Collection
    {
        return $this->receivedGroupInvitations;
    }

    public function addReceivedGroupInvitation(GroupInvitation $receivedGroupInvitation): static
    {
        if (!$this->receivedGroupInvitations->contains($receivedGroupInvitation)) {
            $this->receivedGroupInvitations->add($receivedGroupInvitation);
            $receivedGroupInvitation->setInvitedUser($this);
        }

        return $this;
    }

    public function removeReceivedGroupInvitation(GroupInvitation $receivedGroupInvitation): static
    {
        if ($this->receivedGroupInvitations->removeElement($receivedGroupInvitation)) {
            // set the owning side to null (unless already changed)
            if ($receivedGroupInvitation->getInvitedUser() === $this) {
                $receivedGroupInvitation->setInvitedUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PrivateConversation>
     */
    public function getConversationAsParticipant1(): Collection
    {
        return $this->conversationAsParticipant1;
    }

    public function addConversationAsParticipant1(PrivateConversation $conversationAsParticipant1): static
    {
        if (!$this->conversationAsParticipant1->contains($conversationAsParticipant1)) {
            $this->conversationAsParticipant1->add($conversationAsParticipant1);
            $conversationAsParticipant1->setParticipant1($this);
        }

        return $this;
    }

    public function removeConversationAsParticipant1(PrivateConversation $conversationAsParticipant1): static
    {
        if ($this->conversationAsParticipant1->removeElement($conversationAsParticipant1)) {
            // set the owning side to null (unless already changed)
            if ($conversationAsParticipant1->getParticipant1() === $this) {
                $conversationAsParticipant1->setParticipant1(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PrivateConversation>
     */
    public function getConversationAsParticipant2(): Collection
    {
        return $this->conversationAsParticipant2;
    }

    public function addConversationAsParticipant2(PrivateConversation $conversationAsParticipant2): static
    {
        if (!$this->conversationAsParticipant2->contains($conversationAsParticipant2)) {
            $this->conversationAsParticipant2->add($conversationAsParticipant2);
            $conversationAsParticipant2->setParticipant2($this);
        }

        return $this;
    }

    public function removeConversationAsParticipant2(PrivateConversation $conversationAsParticipant2): static
    {
        if ($this->conversationAsParticipant2->removeElement($conversationAsParticipant2)) {
            // set the owning side to null (unless already changed)
            if ($conversationAsParticipant2->getParticipant2() === $this) {
                $conversationAsParticipant2->setParticipant2(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PrivateMessage>
     */
    public function getPrivateMessages(): Collection
    {
        return $this->privateMessages;
    }

    public function addPrivateMessage(PrivateMessage $privateMessage): static
    {
        if (!$this->privateMessages->contains($privateMessage)) {
            $this->privateMessages->add($privateMessage);
            $privateMessage->setAuthor($this);
        }

        return $this;
    }

    public function removePrivateMessage(PrivateMessage $privateMessage): static
    {
        if ($this->privateMessages->removeElement($privateMessage)) {
            // set the owning side to null (unless already changed)
            if ($privateMessage->getAuthor() === $this) {
                $privateMessage->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ArmyList>
     */
    public function getArmyLists(): Collection
    {
        return $this->armyLists;
    }

    public function addArmyList(ArmyList $armyList): static
    {
        if (!$this->armyLists->contains($armyList)) {
            $this->armyLists->add($armyList);
            $armyList->setOwner($this);
        }

        return $this;
    }

    public function removeArmyList(ArmyList $armyList): static
    {
        if ($this->armyLists->removeElement($armyList)) {
            // set the owning side to null (unless already changed)
            if ($armyList->getOwner() === $this) {
                $armyList->setOwner(null);
            }
        }

        return $this;
    }
}
