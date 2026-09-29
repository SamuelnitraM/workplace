<?php

namespace App\Controller\Admin;

use App\Entity\TodoNode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class TodoNodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TodoNode::class;
    }

    // No creation from the back office: TodoNode.owner is required and not offered
    // by the default fields, tasks are created from the application.
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }
}
