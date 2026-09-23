<?php

namespace App\Entity;

use App\Repository\GalleryPhotoCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GalleryPhotoCommentRepository::class)]
#[ORM\Index(name: 'idx_gallery_photo_comment_photo_created', columns: ['photo_id', 'created_at'])]
class GalleryPhotoComment
{
    public const CONTENT_MAX_LENGTH = 1000;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GalleryPhoto $photo = null;

    // Relation unidirectionnelle : la suppression d'un utilisateur supprime ses commentaires (CASCADE SQL)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::CONTENT_MAX_LENGTH)]
    private string $content = '';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(GalleryPhoto $photo, User $author, string $content)
    {
        $this->photo = $photo;
        $this->author = $author;
        $this->content = trim(str_replace("\r\n", "\n", $content));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPhoto(): ?GalleryPhoto { return $this->photo; }
    public function getAuthor(): ?User { return $this->author; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
