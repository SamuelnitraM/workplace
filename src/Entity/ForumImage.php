<?php

namespace App\Entity;

use App\Repository\ForumImageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Image envoyée depuis l'éditeur du forum (insérée dans un message en Markdown : ![](/uploads/forum/…)).
 * Seules ces images sont affichées dans les messages : les images externes deviennent de simples liens
 * (pas de pistage des lecteurs par un serveur tiers).
 */
#[ORM\Entity(repositoryClass: ForumImageRepository::class)]
#[ORM\Index(name: 'idx_forum_image_uploader_created', columns: ['uploader_id', 'created_at'])]
class ForumImage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $filename;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploader;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $filename, User $uploader)
    {
        $this->filename = $filename;
        $this->uploader = $uploader;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getUploader(): ?User
    {
        return $this->uploader;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
