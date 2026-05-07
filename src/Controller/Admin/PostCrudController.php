<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class PostCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Post::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('thread', 'Sujet')
            ->formatValue(function ($value) {
                return $value ? $value->getTitle() : '-';
            });
        yield AssociationField::new('author', 'Auteur')
            ->formatValue(function ($value) {
                return $value ? $value->getUsername() : '-';
            });
        yield TextareaField::new('content', 'Contenu')
            ->hideOnIndex();
        yield BooleanField::new('isFirst', 'Premier post')
            ->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
    }
}