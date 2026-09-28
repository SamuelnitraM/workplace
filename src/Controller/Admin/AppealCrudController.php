<?php

namespace App\Controller\Admin;

use App\Entity\Appeal;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Appeals of suspended members: read-only list, pending first. The decision is taken on the appeal page
 * (ModerationController::appeal), with the sanction and the report history of the member.
 */
#[IsGranted('ROLE_MODERATOR')]
class AppealCrudController extends AbstractCrudController
{
    private const STATUS_CHOICES = [
        'En attente' => Appeal::STATUS_PENDING,
        'Sanction levée' => Appeal::STATUS_LIFTED,
        'Sanction maintenue' => Appeal::STATUS_UPHELD,
    ];

    public static function getEntityFqcn(): string
    {
        return Appeal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réclamation')
            ->setEntityLabelInPlural('Réclamations')
            ->setDefaultSort(['status' => 'ASC', 'createdAt' => 'ASC'])
            // A click anywhere on a row opens the appeal page
            ->setDefaultRowAction('examine')
            ->setPageTitle(Crud::PAGE_INDEX, 'Réclamations des membres sanctionnés');
    }

    public function configureActions(Actions $actions): Actions
    {
        $examine = Action::new('examine', 'Examiner', 'fa fa-scale-balanced')
            ->linkToRoute('admin_moderation_appeal', static fn (Appeal $appeal): array => ['id' => $appeal->getId()])
            ->asPrimaryAction();
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $examine);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status', 'Statut')->setChoices(self::STATUS_CHOICES));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', '#');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges([Appeal::STATUS_PENDING => 'warning', Appeal::STATUS_LIFTED => 'success', Appeal::STATUS_UPHELD => 'secondary']);
        yield AssociationField::new('member', 'Membre');
        yield TextField::new('message', 'Message')->setMaxLength(90);
        yield DateTimeField::new('suspendedUntil', 'Sanction')
            ->formatValue(static fn ($value, Appeal $appeal): string => $appeal->isAgainstPermanentBan()
                ? 'Bannissement définitif'
                : 'Suspension jusqu\'au ' . $appeal->getSuspendedUntil()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'));
        yield DateTimeField::new('createdAt', 'Reçue le');
        yield AssociationField::new('handledBy', 'Traitée par');
    }
}
