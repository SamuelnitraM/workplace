<?php

namespace App\Entity;

use App\Repository\GalleryPhotoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GalleryPhotoRepository::class)]
class GalleryPhoto
{
    public const DESCRIPTION_MAX_LENGTH = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(length: self::DESCRIPTION_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::DESCRIPTION_MAX_LENGTH)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private bool $isVisible = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $moderationHiddenAt = null;

    #[ORM\ManyToOne(inversedBy: 'galleryPhotos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GalleryAlbum $album = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getFilename(): ?string { return $this->filename; }
    public function setFilename(string $filename): static { $this->filename = $filename; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static
    {
        $description = $description === null ? null : trim(str_replace("\r\n", "\n", $description));
        $this->description = $description === '' ? null : $description;
        return $this;
    }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function isVisible(): bool { return $this->isVisible; }
    public function setIsVisible(bool $isVisible): static { $this->isVisible = $isVisible; return $this; }
    public function getModerationHiddenAt(): ?\DateTimeImmutable { return $this->moderationHiddenAt; }
    public function isHiddenByModeration(): bool { return $this->moderationHiddenAt !== null; }

    /** A photo hidden by moderation is invisible to everyone but its owner, who cannot show it again. */
    public function setHiddenByModeration(bool $hidden): static
    {
        $this->moderationHiddenAt = $hidden ? ($this->moderationHiddenAt ?? new \DateTimeImmutable()) : null;
        $this->isVisible = !$hidden;
        return $this;
    }
    public function getAlbum(): ?GalleryAlbum { return $this->album; }
    public function setAlbum(?GalleryAlbum $album): static { $this->album = $album; return $this; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): static { $this->owner = $owner; return $this; }
}
