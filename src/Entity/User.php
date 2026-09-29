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
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail. Connecte-toi.')]
#[UniqueEntity(fields: ['username'], message: 'Ce pseudo est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const DELETED_MEMBER_EMAIL = 'membre-supprime@sprue.invalid';
    public const DELETED_MEMBER_USERNAME = 'Membre supprimé';

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

    /** Banner image of the profile (public/uploads/covers); the default visual is shown when null. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cover = null;

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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    /** Warning e-mail sent before the deletion of an inactive account (App\Account\InactiveAccountPurger); cleared by any activity. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inactivityWarnedAt = null;


    /**
     * Guided tour (/bienvenue): NULL until it is completed (banner on the home page).
     * Accounts older than the guided tour are marked completed by its migration.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingCompletedAt = null;

    /** Current step of the guided presentation (1 to 4), to resume it where the member left off. */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $onboardingStep = 1;

    /**
     * Title shown next to the username: a badge UNLOCKED by the member (checked by UserTitleManager
     * and by the profile form). Deleted badge → NULL (ON DELETE SET NULL).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Badge $titleBadge = null;

    /** Sound played when a notification or a message arrives (key of App\Notification\NotificationSound, « none » = muted). */
    #[ORM\Column(length: 20, options: ['default' => 'auspex'])]
    private string $notificationSound = 'auspex';

    /** Order of the groups page: 'activity' (most recent activity first) or 'custom' (order arranged by the member). */
    #[ORM\Column(length: 10, options: ['default' => 'activity'])]
    private string $groupSortMode = 'activity';

    public const GROUP_SORT_ACTIVITY = 'activity';
    public const GROUP_SORT_CUSTOM = 'custom';

    /** Start of the current moderation suspension (NULL: account in good standing). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    /** End of the suspension; NULL while suspendedAt is set means a permanent ban. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedUntil = null;

    /** Reason shown to the member at login. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $suspensionReason = null;

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
        // Relation (Doctrine proxy) not stored in the session: reloaded with the user on every request
        unset($data["\0".self::class."\0titleBadge"]);

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

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function setCover(?string $cover): static
    {
        $this->cover = $cover;

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

    public function isShowActivity(): bool
    {
        return $this->showActivity;
    }

    public function setShowActivity(bool $showActivity): static
    {
        $this->showActivity = $showActivity;

        return $this;
    }

    public const MAX_LEVEL = 50;

    public function getExperience(): int { return $this->experience; }
    /** Reserved for GamificationService (atomic SQL writes, then synchronisation of the object). */
    public function setExperience(int $experience): static { $this->experience = max(0, $experience); return $this; }
    public function getLevel(): int
    {
        $level = 1;
        for ($candidate = 2; $candidate <= self::MAX_LEVEL; $candidate++) {
            if ($this->getExperienceForLevel($candidate) > $this->experience) break;
            $level = $candidate;
        }
        return $level;
    }
    public function getExperienceForLevel(int $level): int { return $level <= 1 ? 0 : (int) round(100 * (($level - 1) ** 1.5)); }
    public function isMaxLevel(): bool { return $this->getLevel() >= self::MAX_LEVEL; }
    /** Total XP required for the next level (null at the maximum level). */
    public function getNextLevelExperience(): ?int
    {
        $level = $this->getLevel();
        return $level >= self::MAX_LEVEL ? null : $this->getExperienceForLevel($level + 1);
    }
    public function getExperienceProgress(): int
    {
        $level = $this->getLevel();
        if ($level >= self::MAX_LEVEL) return 100;
        $current = $this->getExperienceForLevel($level);
        $next = $this->getExperienceForLevel($level + 1);
        return (int) max(0, min(100, floor(($this->experience - $current) / max(1, $next - $current) * 100)));
    }
    public function getLastDailyLoginAt(): ?\DateTimeImmutable { return $this->lastDailyLoginAt; }
    public function setLastDailyLoginAt(?\DateTimeImmutable $value): static { $this->lastDailyLoginAt = $value; return $this; }
    public function getLoginStreak(): int { return $this->loginStreak; }
    public function setLoginStreak(int $value): static { $this->loginStreak = max(0, $value); return $this; }
    public function getLastActivityAt(): ?\DateTimeImmutable { return $this->lastActivityAt; }
    public function setLastActivityAt(?\DateTimeImmutable $value): static { $this->lastActivityAt = $value; return $this; }
    public function getInactivityWarnedAt(): ?\DateTimeImmutable { return $this->inactivityWarnedAt; }
    public function setInactivityWarnedAt(?\DateTimeImmutable $value): static { $this->inactivityWarnedAt = $value; return $this; }
    public function isStaff(): bool { return array_intersect(['ROLE_ADMIN', 'ROLE_MODERATOR'], $this->getRoles()) !== []; }
    public function getOnboardingCompletedAt(): ?\DateTimeImmutable { return $this->onboardingCompletedAt; }
    public function setOnboardingCompletedAt(?\DateTimeImmutable $value): static { $this->onboardingCompletedAt = $value; return $this; }
    public function getOnboardingStep(): int { return $this->onboardingStep; }
    public function setOnboardingStep(int $value): static { $this->onboardingStep = $value; return $this; }
    public function getTitleBadge(): ?Badge { return $this->titleBadge; }
    /** Does not check that the badge is unlocked: go through UserTitleManager (or the profile form). */
    public function setTitleBadge(?Badge $titleBadge): static { $this->titleBadge = $titleBadge; return $this; }
    public function getNotificationSound(): string { return $this->notificationSound; }
    public function setNotificationSound(string $notificationSound): static { $this->notificationSound = $notificationSound; return $this; }
    public function getGroupSortMode(): string { return $this->groupSortMode; }
    public function setGroupSortMode(string $groupSortMode): static { $this->groupSortMode = $groupSortMode === self::GROUP_SORT_CUSTOM ? self::GROUP_SORT_CUSTOM : self::GROUP_SORT_ACTIVITY; return $this; }

    public function getSuspendedAt(): ?\DateTimeImmutable { return $this->suspendedAt; }
    public function getSuspendedUntil(): ?\DateTimeImmutable { return $this->suspendedUntil; }
    public function getSuspensionReason(): ?string { return $this->suspensionReason; }

    /** Suspends the account until the given date, or permanently when $until is NULL. */
    public function suspend(?\DateTimeImmutable $until, string $reason): static
    {
        $this->suspendedAt = new \DateTimeImmutable();
        $this->suspendedUntil = $until;
        $this->suspensionReason = $reason;
        return $this;
    }

    public function liftSuspension(): static
    {
        $this->suspendedAt = null;
        $this->suspendedUntil = null;
        $this->suspensionReason = null;
        return $this;
    }

    /** An expired temporary suspension does not count: no cleanup job is needed. */
    public function isSuspended(?\DateTimeImmutable $now = null): bool
    {
        if ($this->suspendedAt === null) {
            return false;
        }
        return $this->suspendedUntil === null || $this->suspendedUntil > ($now ?? new \DateTimeImmutable());
    }

    public function isPermanentlySuspended(): bool
    {
        return $this->suspendedAt !== null && $this->suspendedUntil === null;
    }

    /** Permanently banned: hidden from most of the site, marked « Banni » elsewhere (App\Moderation\BannedMembers). */
    public function isBanned(): bool
    {
        return $this->isPermanentlySuspended() && !$this->isDeletedMemberAccount();
    }

    /**
     * Technical account holding the anonymised contributions of deleted members (App\Account\AccountDeleter).
     * It never logs in and is hidden like a banned member, without the « Banni » mark.
     */
    public function isDeletedMemberAccount(): bool
    {
        return $this->email === self::DELETED_MEMBER_EMAIL;
    }

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

    /**
     * @return Collection<int, Post>
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    /**
     * @return Collection<int, Friendship>
     */
    public function getFriendshipAsRequester(): Collection
    {
        return $this->friendshipAsRequester;
    }

    /**
     * @return Collection<int, Friendship>
     */
    public function getFriendshipAsReceiver(): Collection
    {
        return $this->friendshipAsReceiver;
    }

    /**
     * @return Collection<int, TodoNode>
     */
    public function getTodoNodes(): Collection
    {
        return $this->todoNodes;
    }

    /**
     * @return Collection<int, Group>
     */
    public function getCreatedGroups(): Collection
    {
        return $this->CreatedGroups;
    }

    /**
     * @return Collection<int, GroupMember>
     */
    public function getGroupMembers(): Collection
    {
        return $this->groupMembers;
    }

    /**
     * @return Collection<int, GroupMessage>
     */
    public function getGroupMessages(): Collection
    {
        return $this->groupMessages;
    }

    /**
     * @return Collection<int, GroupInvitation>
     */
    public function getSentGroupInvitations(): Collection
    {
        return $this->sentGroupInvitations;
    }

    /**
     * @return Collection<int, GroupInvitation>
     */
    public function getReceivedGroupInvitations(): Collection
    {
        return $this->receivedGroupInvitations;
    }

    /**
     * @return Collection<int, PrivateConversation>
     */
    public function getConversationAsParticipant1(): Collection
    {
        return $this->conversationAsParticipant1;
    }

    /**
     * @return Collection<int, PrivateConversation>
     */
    public function getConversationAsParticipant2(): Collection
    {
        return $this->conversationAsParticipant2;
    }

    /**
     * @return Collection<int, PrivateMessage>
     */
    public function getPrivateMessages(): Collection
    {
        return $this->privateMessages;
    }

    /**
     * @return Collection<int, ArmyList>
     */
    public function getArmyLists(): Collection
    {
        return $this->armyLists;
    }

}
