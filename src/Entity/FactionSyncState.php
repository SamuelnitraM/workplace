<?php

namespace App\Entity;

use App\Repository\FactionSyncStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FactionSyncStateRepository::class)]
class FactionSyncState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $sourceFile = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastCommitSha = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $unitCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $extractorVersion = null;

    /**
     * Fichiers du graphe de catalogues de la faction (principal, bibliothèques liées, système de jeu) :
     * lastCommitSha contient l'empreinte combinée de leurs SHA de blob (BsDataFetcher::combinedHash()).
     *
     * @var list<string>|null
     */
    #[ORM\Column(nullable: true)]
    private ?array $sourceFiles = null;

    /** @return list<string>|null */
    public function getSourceFiles(): ?array
    {
        return $this->sourceFiles;
    }

    /** @param list<string>|null $sourceFiles */
    public function setSourceFiles(?array $sourceFiles): static
    {
        $this->sourceFiles = $sourceFiles;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(string $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function getLastCommitSha(): ?string
    {
        return $this->lastCommitSha;
    }

    public function setLastCommitSha(?string $lastCommitSha): static
    {
        $this->lastCommitSha = $lastCommitSha;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getUnitCount(): ?int
    {
        return $this->unitCount;
    }

    public function setUnitCount(?int $unitCount): static
    {
        $this->unitCount = $unitCount;

        return $this;
    }

    public function getExtractorVersion(): ?int
    {
        return $this->extractorVersion;
    }

    public function setExtractorVersion(?int $extractorVersion): static
    {
        $this->extractorVersion = $extractorVersion;

        return $this;
    }
}
