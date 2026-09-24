<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    // Pas de création depuis l'admin : User.password est obligatoire et n'est pas géré ici,
    // les utilisateurs s'inscrivent eux-mêmes.
    public function configureActions(Actions $actions): Actions
    {
        $sanctions = Action::new('sanctions', 'Sanctions', 'fa fa-gavel')
            ->linkToRoute('admin_moderation_member', static fn (User $user): array => ['id' => $user->getId()]);
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $sanctions)
            ->add(Crud::PAGE_DETAIL, $sanctions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('username', 'Pseudo');
        yield EmailField::new('email', 'Email');
        yield ChoiceField::new('roles', 'Rôles')
            ->setChoices([
                'Utilisateur' => 'ROLE_USER',
                'Administrateur' => 'ROLE_ADMIN',
                'Modérateur' => 'ROLE_MODERATOR',
            ])
            ->allowMultipleChoices()
            ->renderAsBadges([
                'ROLE_ADMIN' => 'danger',
                'ROLE_MODERATOR' => 'warning',
                'ROLE_USER' => 'success',
            ]);
        yield BooleanField::new('isVerified', 'Email vérifié');
        yield TextareaField::new('bio', 'Biographie')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Inscrit le')
            ->hideOnForm();
        yield DateTimeField::new('suspendedAt', 'Sanction')
            ->hideOnForm()
            ->formatValue(static fn ($value, User $user): string => match (true) {
                !$user->isSuspended() => '-',
                $user->isPermanentlySuspended() => 'Suspendu définitivement',
                default => 'Suspendu jusqu\'au ' . $user->getSuspendedUntil()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'),
            });
    }
}