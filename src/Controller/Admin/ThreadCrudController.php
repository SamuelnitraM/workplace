<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Thread;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ThreadCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Thread::class;
    }

    // Pas de création depuis l'admin : un sujet doit avoir un slug et un premier message
    // (Post isFirst), ce que seul le formulaire du forum sait produire.
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Titre');
        // Seules les catégories qui acceptent des sujets (sauf la catégorie actuelle d'un sujet existant)
        $currentCategory = $this->getContext()?->getEntity()?->getInstance()?->getCategory();
        yield AssociationField::new('category', 'Catégorie')
            ->formatValue(function ($value) {
                return $value ? $value->getPath() : '-';
            })
            ->setQueryBuilder(function (QueryBuilder $qb) use ($currentCategory) {
                $alias = $qb->getRootAliases()[0];
                if ($currentCategory) {
                    $qb->andWhere(sprintf('%1$s.allowThreads = true OR %1$s = :currentCategory', $alias))
                        ->setParameter('currentCategory', $currentCategory);
                } else {
                    $qb->andWhere(sprintf('%s.allowThreads = true', $alias));
                }

                return $qb;
            })
            ->setFormTypeOption('choice_label', fn (Category $category) => $category->getPath());
        yield AssociationField::new('author', 'Auteur')
            ->formatValue(function ($value) {
                return $value ? $value->getUsername() : '-';
            });
        yield BooleanField::new('isPinned', 'Épinglé');
        yield BooleanField::new('isLocked', 'Fermé');
        yield IntegerField::new('views', 'Vues');
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}