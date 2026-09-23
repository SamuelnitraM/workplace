<?php

namespace App\Controller\Admin;

use App\Entity\TodoNode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TodoNodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TodoNode::class;
    }

    // Pas de création depuis l'admin : TodoNode.owner est obligatoire et n'est pas proposé
    // par les champs par défaut, les tâches se créent depuis l'application.
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    /*
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            TextField::new('title'),
            TextEditorField::new('description'),
        ];
    }
    */
}
