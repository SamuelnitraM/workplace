<?php

namespace App\Controller\Admin;

use App\Entity\Badge;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BadgeCrudController extends AbstractCrudController
{
	public static function getEntityFqcn(): string { return Badge::class; }

	public function configureCrud(Crud $crud): Crud
	{
		return $crud->setEntityLabelInSingular('Badge')->setEntityLabelInPlural('Badges');
	}

	public function configureFields(string $pageName): iterable
	{
		yield IdField::new('id')->hideOnForm();
		yield TextField::new('code', 'Code');
		yield TextField::new('name', 'Titre');
		yield TextField::new('category', 'Catégorie');
		yield TextareaField::new('description', 'Condition de déblocage');
		yield TextareaField::new('hiddenDescription', 'Texte secret avant déblocage');
		yield TextField::new('icon', 'Icône');
		yield IntegerField::new('xpReward', 'Récompense XP');
		yield BooleanField::new('hidden', 'Badge caché')->renderAsSwitch(true);
	}
}
