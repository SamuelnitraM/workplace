<?php

namespace App\Controller\Admin;

use App\Entity\Badge;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Badges : LECTURE SEULE.
 *
 * Les badges (textes, XP, conditions) sont définis dans le code (App\Gamification\BadgeCatalog) et
 * recopiés dans la table par `php bin/console app:gamification:sync-badges`, qui écraserait toute
 * modification faite ici : création, édition et suppression sont donc désactivées.
 */
class BadgeCrudController extends AbstractCrudController
{
	public static function getEntityFqcn(): string { return Badge::class; }

	public function configureCrud(Crud $crud): Crud
	{
		return $crud
			->setEntityLabelInSingular('Badge')
			->setEntityLabelInPlural('Badges')
			->setDefaultSort(['category' => 'ASC', 'xpReward' => 'ASC'])
			->setHelp(Crud::PAGE_INDEX, 'Badges définis dans le code (App\Gamification\BadgeCatalog), en lecture seule. Après une modification du catalogue : php bin/console app:gamification:sync-badges');
	}

	public function configureActions(Actions $actions): Actions
	{
		return $actions
			->add(Crud::PAGE_INDEX, Action::DETAIL)
			->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
	}

	public function configureFields(string $pageName): iterable
	{
		yield IdField::new('id')->hideOnIndex();
		yield TextField::new('icon', 'Icône');
		yield TextField::new('code', 'Code');
		yield TextField::new('name', 'Titre');
		yield TextField::new('category', 'Catégorie');
		yield TextareaField::new('description', 'Condition de déblocage');
		yield IntegerField::new('xpReward', 'Récompense XP');
		yield TextField::new('tierLabel', 'Palier');
	}
}
