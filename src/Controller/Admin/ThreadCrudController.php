<?php

namespace App\Controller\Admin;

use App\Entity\Thread;
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

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Titre');
        yield AssociationField::new('category', 'Catégorie')
            ->formatValue(function ($value) {
                return $value ? $value->getName() : '-';
            });
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