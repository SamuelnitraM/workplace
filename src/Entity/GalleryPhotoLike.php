<?php

namespace App\Entity;

use App\Repository\GalleryPhotoLikeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GalleryPhotoLikeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_gallery_photo_like', columns: ['photo_id', 'user_id'])]
class GalleryPhotoLike
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GalleryPhoto $photo = null;

    // Relation unidirectionnelle : la suppression d'un utilisateur supprime ses likes (CASCADE SQL)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(GalleryPhoto $photo, User $user)
    {
        $this->photo = $photo;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPhoto(): ?GalleryPhoto { return $this->photo; }
    public function getUser(): ?User { return $this->user; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
