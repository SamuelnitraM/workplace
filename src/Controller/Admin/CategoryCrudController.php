<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[IsGranted('ROLE_ADMIN')]
class CategoryCrudController extends AbstractCrudController implements EventSubscriberInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => 'reorderOnPersist',
            BeforeEntityUpdatedEvent::class => 'reorderOnUpdate',
            BeforeEntityDeletedEvent::class => 'reattachChildrenOnDelete',
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // Charge tout l'arbre en une requête : les libellés « chemin complet »
        // (parent › enfant) ne déclenchent ensuite aucune requête supplémentaire.
        $tree = $this->categoryRepository->findAllAsTree();

        // En édition : exclure la catégorie elle-même et tous ses descendants (anti-cycle).
        $excludedIds = [];
        $current = $this->getContext()?->getEntity()?->getInstance();
        if ($current instanceof Category && $current->getId() !== null) {
            $self = $tree[$current->getId()] ?? $current;
            $excludedIds[] = $self->getId();
            foreach ($self->getDescendants() as $descendant) {
                $excludedIds[] = $descendant->getId();
            }
        }

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom');
        yield AssociationField::new('parent', 'Catégorie parente')
            ->setRequired(false)
            ->setHelp('Laisser vide pour une catégorie racine. Une catégorie ne peut pas être rattachée à l\'une de ses sous-catégories.')
            ->setQueryBuilder(static function (QueryBuilder $qb) use ($excludedIds): QueryBuilder {
                if ($excludedIds) {
                    $qb->andWhere('entity.id NOT IN (:excludedIds)')
                        ->setParameter('excludedIds', $excludedIds);
                }

                return $qb->orderBy('entity.position', 'ASC')->addOrderBy('entity.id', 'ASC');
            })
            ->setFormTypeOption('choice_label', static fn (Category $c): string => $c->getPath())
            ->setFormTypeOption('placeholder', '— Aucune (catégorie racine) —')
            ->formatValue(static fn ($value, ?Category $entity): string => $entity?->getParent()?->getPath() ?? '—');
        yield TextField::new('slug', 'Slug');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        // Sur l'index, l'interrupteur EasyAdmin (PATCH AJAX sur l'action edit) déclenche
        // les mêmes événements qu'une édition classique.
        yield BooleanField::new('allowThreads', 'Création de sujets')
            ->setHelp('Si désactivé, la catégorie sert uniquement de regroupement : ses sous-catégories sont affichées en liste et aucun sujet ne peut y être créé.');
        yield BooleanField::new('readOnly', 'Lecture seule')
            ->setHelp('Seuls les administrateurs peuvent créer des sujets et répondre ; les autres membres lisent uniquement. Les sujets de ces catégories alimentent le filtre « Actualités » du fil.');
        yield ChoiceField::new('icon', 'Icône')
            ->setChoices($this->iconChoices())
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Icône affichée à côté de la catégorie. Sans icône, aucun pictogramme n\'est affiché.');
        yield IntegerField::new('position', 'Position')
            ->setHelp('Position parmi les catégories de même niveau (même parent).');
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }

    /**
     * Icon names of the design system, as label => value choices.
     *
     * @return array<string, string>
     */
    private function iconChoices(): array
    {
        $names = explode(',', trim($this->twig->render('_partials/_icon.html.twig', ['name' => '__names'])));
        sort($names);

        return array_combine($names, $names);
    }

    public function createEntity(string $entityFqcn): Category
    {
        // Le parent n'est pas encore connu : on propose « en dernier », la position
        // est ensuite recalée parmi les catégories sœurs à l'enregistrement.
        $category = new Category();
        $lastCategory = $this->categoryRepository->findOneBy([], ['position' => 'DESC']);
        $newPosition = $lastCategory ? $lastCategory->getPosition() + 1 : 1;
        $category->setPosition($newPosition);

        return $category;
    }

    // À la création : insertion à la position demandée parmi les sœurs
    public function reorderOnPersist(BeforeEntityPersistedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (!$entity instanceof Category) return;

        $this->reorderSiblings($entity);
        $this->warnIfEmptyGrouping($entity);
    }

    // À la mise à jour : réordonnancement complet des sœurs (et de l'ancien niveau si le parent change)
    public function reorderOnUpdate(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (!$entity instanceof Category) return;

        $original = $this->em->getUnitOfWork()->getOriginalEntityData($entity);
        $oldParent = $original['parent'] ?? null;

        if ($oldParent !== $entity->getParent()) {
            // Refermer le « trou » laissé dans l'ancien niveau
            $this->renumber($this->categoryRepository->findSiblings($oldParent, $entity->getId()));
        }

        $this->reorderSiblings($entity);
        $this->warnIfEmptyGrouping($entity);
    }

    // À la suppression : les sous-catégories remontent d'un niveau (rattachées au grand-parent)
    public function reattachChildrenOnDelete(BeforeEntityDeletedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (!$entity instanceof Category) return;

        $newParent = $entity->getParent();
        $siblings = $this->categoryRepository->findSiblings($newParent, $entity->getId());

        foreach ($entity->getChildren()->toArray() as $child) {
            $child->setParent($newParent);
            $siblings[] = $child;
        }

        $this->renumber($siblings);
    }

    /**
     * Création de sujets désactivée sans sous-catégorie : regroupement vide.
     * Autorisé, mais l'administrateur est averti.
     */
    private function warnIfEmptyGrouping(Category $entity): void
    {
        if ($entity->isAllowThreads() || !$entity->getChildren()->isEmpty()) {
            return;
        }

        $this->addFlash('warning', sprintf(
            'La catégorie « %s » n\'autorise pas la création de sujets mais n\'a aucune sous-catégorie : ce regroupement est vide pour le moment.',
            $entity->getName()
        ));
    }

    /**
     * Place $entity à sa position (bornée) parmi ses sœurs et renumérote celles-ci.
     */
    private function reorderSiblings(Category $entity): void
    {
        $siblings = $this->categoryRepository->findSiblings($entity->getParent(), $entity->getId());

        $total = count($siblings) + 1;
        $newPosition = max(1, min((int) $entity->getPosition(), $total));
        $entity->setPosition($newPosition);

        // Réassigner les positions en laissant la place à notre catégorie
        $position = 1;
        foreach ($siblings as $category) {
            if ($position == $newPosition) {
                $position++; // sauter la position réservée à notre catégorie
            }
            $category->setPosition($position);
            $this->em->persist($category);
            $position++;
        }
    }

    /**
     * @param Category[] $categories
     */
    private function renumber(array $categories): void
    {
        $position = 1;
        foreach ($categories as $category) {
            $category->setPosition($position++);
            $this->em->persist($category);
        }
    }
}
