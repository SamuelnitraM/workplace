<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification du centre de notifications.
 *
 * Les notifications « agrégeables » partagent une clé (groupKey) : tant qu'une notification
 * non lue existe pour le même destinataire et la même clé, elle est mise à jour (compteur,
 * dernier acteur, date) au lieu d'en créer une nouvelle.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'idx_notification_recipient_read', columns: ['recipient_id', 'read_at'])]
#[ORM\Index(name: 'idx_notification_recipient_group', columns: ['recipient_id', 'group_key'])]
class Notification
{
    public const TYPE_FRIEND_REQUEST = 'friend_request';
    public const TYPE_FRIEND_ACCEPTED = 'friend_accepted';
    public const TYPE_GROUP_INVITATION = 'group_invitation';
    public const TYPE_GROUP_INVITATION_ACCEPTED = 'group_invitation_accepted';
    public const TYPE_GROUP_MESSAGE = 'group_message';
    public const TYPE_FORUM_REPLY = 'forum_reply';
    public const TYPE_PHOTO_LIKE = 'photo_like';
    public const TYPE_PHOTO_COMMENT = 'photo_comment';
    public const TYPE_BADGE_EARNED = 'badge_earned';
    public const TYPE_LEVEL_UP = 'level_up';
    public const TYPE_FORUM_MENTION = 'forum_mention';
    public const TYPE_FORUM_SOLUTION = 'forum_solution';

    public const TYPES = [
        self::TYPE_FORUM_MENTION,
        self::TYPE_FORUM_SOLUTION,
        self::TYPE_FRIEND_REQUEST,
        self::TYPE_FRIEND_ACCEPTED,
        self::TYPE_GROUP_INVITATION,
        self::TYPE_GROUP_INVITATION_ACCEPTED,
        self::TYPE_GROUP_MESSAGE,
        self::TYPE_FORUM_REPLY,
        self::TYPE_PHOTO_LIKE,
        self::TYPE_PHOTO_COMMENT,
        self::TYPE_BADGE_EARNED,
        self::TYPE_LEVEL_UP,
    ];

    /** Types dont le compteur représente des personnes distinctes (« X et 3 autres ont aimé… »). */
    public const DISTINCT_ACTOR_TYPES = [self::TYPE_PHOTO_LIKE];

    /** Nombre maximal d'identifiants d'acteurs conservés dans les données d'une notification agrégée. */
    private const MAX_TRACKED_ACTORS = 100;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    // Relation unidirectionnelle : la suppression du destinataire supprime ses notifications (CASCADE SQL)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $groupKey = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $count = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $recipient, string $type, ?User $actor, array $data, string $url, ?string $groupKey = null)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Type de notification inconnu : %s', $type));
        }

        $this->recipient = $recipient;
        $this->type = $type;
        $this->actor = $actor;
        $this->url = $url;
        $this->groupKey = $groupKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->data = $data;
        if ($actor?->getId() !== null) {
            $this->data['actorIds'] = [$actor->getId()];
        }
    }

    /**
     * Agrège un nouvel évènement dans cette notification (non lue) : incrémente le compteur,
     * met à jour l'acteur, les données et la date. Pour les types « par personne », un même
     * acteur n'est compté qu'une fois.
     */
    public function aggregate(?User $actor, array $data, string $url): void
    {
        $actorIds = $this->data['actorIds'] ?? [];
        $actorId = $actor?->getId();
        $alreadyCounted = $actorId !== null && in_array($actorId, $actorIds, true);

        if (!in_array($this->type, self::DISTINCT_ACTOR_TYPES, true) || !$alreadyCounted) {
            ++$this->count;
        }
        if ($actorId !== null && !$alreadyCounted) {
            $actorIds[] = $actorId;
        }

        $this->data = array_merge($this->data, $data);
        $this->data['actorIds'] = array_slice($actorIds, -self::MAX_TRACKED_ACTORS);
        $this->actor = $actor ?? $this->actor;
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): User { return $this->recipient; }
    public function getActor(): ?User { return $this->actor; }
    public function getType(): string { return $this->type; }
    public function getData(): array { return $this->data; }
    public function getUrl(): string { return $this->url; }
    public function getGroupKey(): ?string { return $this->groupKey; }
    public function getCount(): int { return $this->count; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function isRead(): bool { return $this->readAt !== null; }

    public function markAsRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }
}
