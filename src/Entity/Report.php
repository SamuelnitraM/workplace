<?php

namespace App\Entity;

use App\Moderation\ReportReason;
use App\Moderation\ReportResolution;
use App\Moderation\ReportTargetType;
use App\Repository\ReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Report of a content or profile by a member.
 *
 * The target is polymorphic (type + id). The excerpt and the author are captured at report time,
 * so the moderation history stays readable once the content is edited or deleted.
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Index(name: 'idx_report_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_report_target', columns: ['target_type', 'target_id'])]
class Report
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLOSED = 'closed';
    public const DETAILS_MAX_LENGTH = 1000;
    public const EXCERPT_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reporter;

    #[ORM\Column(length: 20, enumType: ReportTargetType::class)]
    private ReportTargetType $targetType;

    #[ORM\Column]
    private int $targetId;

    /** Author of the reported content (the profile owner for a profile report). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $targetAuthor;

    #[ORM\Column(length: 20, enumType: ReportReason::class)]
    private ReportReason $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details;

    #[ORM\Column(length: self::EXCERPT_MAX_LENGTH)]
    private string $excerpt;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $targetUrl;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20, nullable: true, enumType: ReportResolution::class)]
    private ?ReportResolution $resolution = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $moderatorNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    public function __construct(
        User $reporter,
        ReportTargetType $targetType,
        int $targetId,
        ?User $targetAuthor,
        ReportReason $reason,
        ?string $details,
        string $excerpt,
        ?string $targetUrl,
    ) {
        $this->reporter = $reporter;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->targetAuthor = $targetAuthor;
        $this->reason = $reason;
        $this->details = $details !== null && trim($details) !== '' ? mb_substr(trim($details), 0, self::DETAILS_MAX_LENGTH) : null;
        $this->excerpt = mb_substr($excerpt, 0, self::EXCERPT_MAX_LENGTH);
        $this->targetUrl = $targetUrl;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function close(ReportResolution $resolution, User $moderator, ?string $note): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->resolution = $resolution;
        $this->handledBy = $moderator;
        $this->handledAt = new \DateTimeImmutable();
        $this->moderatorNote = $note !== null && trim($note) !== '' ? trim($note) : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getReporter(): ?User { return $this->reporter; }
    public function getTargetType(): ReportTargetType { return $this->targetType; }
    public function getTargetId(): int { return $this->targetId; }
    public function getTargetAuthor(): ?User { return $this->targetAuthor; }
    public function getReason(): ReportReason { return $this->reason; }
    public function getDetails(): ?string { return $this->details; }
    public function getExcerpt(): string { return $this->excerpt; }
    public function getTargetUrl(): ?string { return $this->targetUrl; }
    public function getStatus(): string { return $this->status; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getResolution(): ?ReportResolution { return $this->resolution; }
    public function getModeratorNote(): ?string { return $this->moderatorNote; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getHandledAt(): ?\DateTimeImmutable { return $this->handledAt; }
    public function getHandledBy(): ?User { return $this->handledBy; }

    public function __toString(): string
    {
        return sprintf('#%d %s', (int) $this->id, $this->targetType->label());
    }
}
