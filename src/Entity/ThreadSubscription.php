<?php

namespace App\Entity;

use App\Repository\ThreadSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Abonnement d'un membre à un sujet du forum : il est notifié de chaque nouvelle réponse.
 * Créé automatiquement pour l'auteur du sujet et pour chaque membre qui répond ; bouton « Suivre » sinon.
 */
#[ORM\Entity(repositoryClass: ThreadSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_THREAD_SUBSCRIPTION', columns: ['user_id', 'thread_id'])]
class ThreadSubscription
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Thread $thread;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Thread $thread)
    {
        $this->user = $user;
        $this->thread = $thread;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getThread(): Thread
    {
        return $this->thread;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
