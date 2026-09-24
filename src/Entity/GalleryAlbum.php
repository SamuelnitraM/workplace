<?php

namespace App\Entity;

use App\Repository\GalleryAlbumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Album of a member's gallery, used like a folder: a photo belongs to one album at most.
 * Deleting an album keeps its photos, which go back to the root of the gallery (ON DELETE SET NULL).
 */
#[ORM\Entity(repositoryClass: GalleryAlbumRepository::class)]
class GalleryAlbum
{
    public const NAME_MAX_LENGTH = 80;
    public const MAX_ALBUMS_PER_MEMBER = 20;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, GalleryPhoto> */
    #[ORM\OneToMany(targetEntity: GalleryPhoto::class, mappedBy: 'album')]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $photos;

    public function __construct(User $owner, string $name)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->photos = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, GalleryPhoto> */
    public function getPhotos(): Collection { return $this->photos; }
}
