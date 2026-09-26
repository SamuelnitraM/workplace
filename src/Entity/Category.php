<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?int $position = null;

    /**
     * « Création de sujets » : si faux, la catégorie ne sert que de regroupement
     * (ses sous-catégories sont listées, aucun nouveau sujet ne peut y être créé).
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $allowThreads = true;

    /**
     * Read-only category: only administrators may create threads or reply; other members can only read.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $readOnly = false;

    /**
     * Optional icon name (templates/_partials/_icon.html.twig) shown next to the category; none when null.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $icon = null;

    /**
     * @var Collection<int, Thread>
     */
    #[ORM\OneToMany(targetEntity: Thread::class, mappedBy: 'category', orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $threads;

    /**
     * Catégorie parente (null = catégorie racine).
     * ON DELETE SET NULL : supprimer un parent ne casse jamais la base, ses enfants
     * remontent au pire en racine (l'admin les rattache d'abord au grand-parent).
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $parent = null;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->position = 0;
        $this->threads = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isAllowThreads(): bool
    {
        return $this->allowThreads;
    }

    public function setAllowThreads(bool $allowThreads): static
    {
        $this->allowThreads = $allowThreads;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): static
    {
        $this->readOnly = $readOnly;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon !== '' ? $icon : null;

        return $this;
    }

    /**
     * Vrai si la catégorie ne sert que de regroupement (création de sujets désactivée).
     */
    public function isGrouping(): bool
    {
        return !$this->allowThreads;
    }

    /**
     * @return Collection<int, Thread>
     */
    public function getThreads(): Collection
    {
        return $this->threads;
    }

    public function addThread(Thread $thread): static
    {
        if (!$this->threads->contains($thread)) {
            $this->threads->add($thread);
            $thread->setCategory($this);
        }

        return $this;
    }

    public function removeThread(Thread $thread): static
    {
        if ($this->threads->removeElement($thread)) {
            // set the owning side to null (unless already changed)
            if ($thread->getCategory() === $this) {
                $thread->setCategory(null);
            }
        }

        return $this;
    }

    public function getParent(): ?Category
    {
        return $this->parent;
    }

    public function setParent(?Category $parent): static
    {
        if ($this->parent === $parent) {
            return $this;
        }

        $this->parent?->getChildren()->removeElement($this);
        $this->parent = $parent;
        if ($parent !== null && !$parent->getChildren()->contains($this)) {
            $parent->getChildren()->add($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Category $child): static
    {
        $child->setParent($this);

        return $this;
    }

    public function removeChild(Category $child): static
    {
        if ($child->getParent() === $this) {
            $child->setParent(null);
        }

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    /**
     * Ancêtres, de la racine vers le parent direct (la catégorie elle-même est exclue).
     * Protégé contre un éventuel cycle présent en base.
     *
     * @return list<Category>
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $seen = [spl_object_id($this) => true];
        $current = $this->parent;

        while ($current !== null && !isset($seen[spl_object_id($current)])) {
            $seen[spl_object_id($current)] = true;
            array_unshift($ancestors, $current);
            $current = $current->getParent();
        }

        return $ancestors;
    }

    /**
     * Chemin complet racine → catégorie (fil d'Ariane).
     *
     * @return list<Category>
     */
    public function getBreadcrumb(): array
    {
        return [...$this->getAncestors(), $this];
    }

    /**
     * Libellé complet, ex. « Warhammer › Warhammer 40k › Space Marines ».
     */
    public function getPath(string $separator = ' › '): string
    {
        return implode($separator, array_map(
            static fn (Category $c): string => (string) $c->getName(),
            $this->getBreadcrumb()
        ));
    }

    /**
     * Profondeur : 0 pour une racine, 1 pour un enfant direct, etc.
     */
    public function getDepth(): int
    {
        return \count($this->getAncestors());
    }

    /**
     * Vrai si la catégorie courante est un ancêtre (strict) de $category.
     */
    public function isAncestorOf(Category $category): bool
    {
        return \in_array($this, $category->getAncestors(), true);
    }

    /**
     * Tous les descendants (parcours en profondeur, dans l'ordre des positions).
     *
     * @return list<Category>
     */
    public function getDescendants(): array
    {
        $result = [];
        $seen = [spl_object_id($this) => true];
        $stack = array_reverse($this->children->toArray());

        while ($stack) {
            $node = array_pop($stack);
            if (isset($seen[spl_object_id($node)])) {
                continue;
            }
            $seen[spl_object_id($node)] = true;
            $result[] = $node;
            foreach (array_reverse($node->getChildren()->toArray()) as $child) {
                $stack[] = $child;
            }
        }

        return $result;
    }

    /**
     * Empêche les cycles : une catégorie ne peut être rattachée ni à elle-même
     * ni à l'une de ses sous-catégories.
     */
    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        $seen = [];
        $current = $this->parent;

        while ($current !== null) {
            if ($current === $this || ($this->id !== null && $current->getId() === $this->id)) {
                $context->buildViolation('Une catégorie ne peut pas être rattachée à elle-même ni à l\'une de ses sous-catégories.')
                    ->atPath('parent')
                    ->addViolation();

                return;
            }
            if (isset($seen[spl_object_id($current)])) {
                return; // cycle préexistant plus haut, sans lien avec cette catégorie
            }
            $seen[spl_object_id($current)] = true;
            $current = $current->getParent();
        }
    }
}
