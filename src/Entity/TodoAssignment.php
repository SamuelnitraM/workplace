<?php

namespace App\Entity;

use App\Repository\TodoAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Member assigned to a task of a group to-do list. A request made by the member stays pending until a manager
 * of the assignments accepts it (App\Todo\TodoAssignmentManager); an accepted assignment gives the right
 * to make the task progress.
 */
#[ORM\Entity(repositoryClass: TodoAssignmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_todo_assignment', columns: ['node_id', 'user_id'])]
class TodoAssignment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TodoNode $node;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 10)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(TodoNode $node, User $user, string $status)
    {
        $this->node = $node;
        $this->user = $user;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): TodoNode
    {
        return $this->node;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function accept(): void
    {
        $this->status = self::STATUS_ACCEPTED;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
