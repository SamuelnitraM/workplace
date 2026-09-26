<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Moderation\ReportReason;
use App\Moderation\ReportResolution;
use App\Moderation\ReportTargetType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Moderation queue: read-only list of reports. Decisions are taken on the report page
 * (ModerationController), which acts on every pending report of the same content.
 */
#[IsGranted('ROLE_MODERATOR')]
class ReportCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Report::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Signalement')
            ->setEntityLabelInPlural('Signalements')
            ->setDefaultSort(['status' => 'DESC', 'createdAt' => 'ASC'])
            // A click anywhere on a row opens the report page
            ->setDefaultRowAction('process')
            ->setPageTitle(Crud::PAGE_INDEX, 'File de modération');
    }

    public function configureActions(Actions $actions): Actions
    {
        $process = Action::new('process', 'Traiter', 'fa fa-gavel')
            ->linkToRoute('admin_moderation_report', static fn (Report $report): array => ['id' => $report->getId()])
            ->asPrimaryAction();
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $process);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(['En attente' => Report::STATUS_PENDING, 'Traité' => Report::STATUS_CLOSED]))
            ->add(ChoiceFilter::new('targetType', 'Type')->setChoices(self::enumChoices(ReportTargetType::cases())))
            ->add(ChoiceFilter::new('reason', 'Motif')->setChoices(self::enumChoices(ReportReason::cases())))
            ->add(ChoiceFilter::new('resolution', 'Décision')->setChoices(self::enumChoices(ReportResolution::cases())));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', '#');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(['En attente' => Report::STATUS_PENDING, 'Traité' => Report::STATUS_CLOSED])
            ->renderAsBadges([Report::STATUS_PENDING => 'warning', Report::STATUS_CLOSED => 'secondary']);
        yield ChoiceField::new('targetType', 'Type')->setChoices(self::enumChoices(ReportTargetType::cases()));
        yield ChoiceField::new('reason', 'Motif')->setChoices(self::enumChoices(ReportReason::cases()));
        yield TextField::new('excerpt', 'Contenu signalé')
            ->setMaxLength(80);
        yield AssociationField::new('targetAuthor', 'Auteur');
        yield AssociationField::new('reporter', 'Signalé par');
        yield DateTimeField::new('createdAt', 'Reçu le');
        yield Field::new('resolution', 'Décision')->setTemplatePath('admin/field/resolution.html.twig');
    }

    /**
     * Label => stored value: EasyAdmin then displays the label of a backed enum value.
     *
     * @param list<ReportTargetType|ReportReason|ReportResolution> $cases
     * @return array<string, string>
     */
    private static function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->label()] = $case->value;
        }
        return $choices;
    }
}
