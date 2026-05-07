<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CategoryCrudController extends AbstractCrudController implements EventSubscriberInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $em
    ) {}

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityUpdatedEvent::class => 'reorderOnUpdate',
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['position' => 'ASC']);
    }

    // À la mise à jour : réordonnancement complet
    public function reorderOnUpdate(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (!$entity instanceof Category) return;

        $newPosition = $entity->getPosition();

        // Récupérer toutes les autres catégories triées par position
        $allCategories = $this->categoryRepository->createQueryBuilder('c')
            ->where('c.id != :id')
            ->setParameter('id', $entity->getId())
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();

        // Reconstruire la liste en insérant la catégorie à la nouvelle position
        $reordered = [];
        $inserted = false;

        foreach ($allCategories as $category) {
            // Quand on atteint la position cible, on insère d'abord notre catégorie
            if (!$inserted && count($reordered) + 1 >= $newPosition) {
                $inserted = true;
            }
            $reordered[] = $category;
        }

        // Réassigner les positions en laissant la place à notre catégorie
        $position = 1;
        foreach ($reordered as $category) {
            if ($position == $newPosition) {
                $position++; // sauter la position réservée à notre catégorie
            }
            $category->setPosition($position);
            $this->em->persist($category);
            $position++;
        }

        // S'assurer que la position ne dépasse pas le nombre total
        $total = count($allCategories) + 1;
        if ($newPosition > $total) {
            $entity->setPosition($total);
        }
    }

    public function createEntity(string $entityFqcn): Category
    {
        $category = new Category();
        $lastCategory = $this->categoryRepository->findOneBy([], ['position' => 'DESC']);
        $newPosition = $lastCategory ? $lastCategory->getPosition() + 1 : 1;
        $category->setPosition($newPosition);

        return $category;
    }
}