<?php

namespace App\Controller\Admin;

use App\Entity\Friendship;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class FriendshipCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Friendship::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('requester', 'Demandeur')
            ->formatValue(function ($value) {
                return $value ? $value->getUsername() : '-';
            });
        yield AssociationField::new('receiver', 'Destinataire')
            ->formatValue(function ($value) {
                return $value ? $value->getUsername() : '-';
            });
        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'En attente' => 'pending',
                'Accepté' => 'accepted',
                'Bloqué' => 'blocked',
            ])
            ->renderAsBadges([
                'pending' => 'warning',
                'accepted' => 'success',
                'blocked' => 'danger',
            ]);
        yield DateTimeField::new('createdAt', 'Date')
            ->hideOnForm();
    }
}