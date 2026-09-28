<?php

namespace App\Entity;

use App\Repository\AppealRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appeal of a suspended member against the sanction, sent from the login page.
 * The sanction (reason and end) is captured when the appeal is sent, so the moderation keeps
 * what the member was answering to; the decision lifts the sanction or upholds it, with an answer sent by e-mail.
 */
#[ORM\Entity(repositoryClass: AppealRepository::class)]
#[ORM\Index(name: 'idx_appeal_status_created', columns: ['status', 'created_at'])]
class Appeal
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_LIFTED = 'lifted';
    public const STATUS_UPHELD = 'upheld';
    public const MESSAGE_MIN_LENGTH = 20;
    public const MESSAGE_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $member;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suspensionReason;

    /** End of the sanction when the appeal was sent, NULL for a permanent ban. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedUntil;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    /** Answer of the moderation, sent to the member by e-mail. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $response = null;

    public function __construct(User $member, string $message)
    {
        $this->member = $member;
        $this->message = mb_substr(trim($message), 0, self::MESSAGE_MAX_LENGTH);
        $this->suspensionReason = $member->getSuspensionReason();
        $this->suspendedUntil = $member->getSuspendedUntil();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function decide(bool $liftSanction, User $moderator, string $response): void
    {
        $this->status = $liftSanction ? self::STATUS_LIFTED : self::STATUS_UPHELD;
        $this->handledBy = $moderator;
        $this->handledAt = new \DateTimeImmutable();
        $this->response = trim($response);
    }

    public function getId(): ?int { return $this->id; }
    public function getMember(): User { return $this->member; }
    public function getMessage(): string { return $this->message; }
    public function getSuspensionReason(): ?string { return $this->suspensionReason; }
    public function getSuspendedUntil(): ?\DateTimeImmutable { return $this->suspendedUntil; }
    public function isAgainstPermanentBan(): bool { return $this->suspendedUntil === null; }
    public function getStatus(): string { return $this->status; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getHandledAt(): ?\DateTimeImmutable { return $this->handledAt; }
    public function getHandledBy(): ?User { return $this->handledBy; }
    public function getResponse(): ?string { return $this->response; }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_LIFTED => 'Sanction levée',
            self::STATUS_UPHELD => 'Sanction maintenue',
            default => 'En attente',
        };
    }

    public function __toString(): string
    {
        return sprintf('Réclamation #%d', (int) $this->id);
    }
}
